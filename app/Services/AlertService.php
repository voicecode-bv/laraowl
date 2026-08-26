<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AlertService
{
    protected IntegrationService $integrationService;

    public function __construct(IntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    /**
     * Notify about an exception issue occurrence.
     *
     * Called on every occurrence; each rule decides whether its occurrence
     * threshold and time window are satisfied before an alert is dispatched.
     */
    public function notifyNewIssue(Issue $issue)
    {
        $project = $issue->project;
        $rules = $this->rulesMatchingIssue($project, 'new_exception', $issue);

        $title = '🚨 New Exception: '.$issue->title;
        $message = $issue->message;
        $url = $issue->url();
        $fields = [
            'Project' => $project->name,
            'Type' => $issue->type,
            'Priority' => strtoupper($issue->priority),
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about a slow performance issue occurrence.
     *
     * Called on every occurrence; each rule decides whether its occurrence
     * threshold and time window are satisfied before an alert is dispatched.
     */
    public function notifySlowPerformance(Issue $issue)
    {
        $project = $issue->project;
        $rules = $this->rulesMatchingIssue($project, 'high_latency', $issue);

        $title = '⏱️ High Latency: '.$issue->title;
        $message = $issue->message;
        $url = $issue->url();
        $fields = [
            'Project' => $project->name,
            'Priority' => strtoupper($issue->priority),
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about uptime down.
     */
    public function notifyUptimeDown(Project $project, int $statusCode, ?string $error = null)
    {
        $rules = $project->alertRules()
            ->where('event_type', 'uptime_down')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '🚨 Uptime Alert: Site is DOWN!';
        $message = "The site returned a {$statusCode} status code.".($error ? "\nError: {$error}" : '');
        $url = $project->dashboardUrl();
        $fields = [
            'Project' => $project->name,
            'URL' => $project->url,
            'Status' => $statusCode,
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about heartbeat failure.
     */
    public function notifyHeartbeatFailed($heartbeat)
    {
        $project = $heartbeat->project;
        $rules = $project->alertRules()
            ->where('event_type', 'heartbeat_failed')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '💓 Heartbeat Failure: '.$heartbeat->name;
        $message = "The heartbeat '{$heartbeat->name}' has stopped checking in.";
        $url = $project->dashboardUrl();
        $fields = [
            'Project' => $project->name,
            'Last Seen' => $heartbeat->last_seen_at ? $heartbeat->last_seen_at->diffForHumans() : 'Never',
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about an error spike.
     */
    public function notifyErrorSpike(Project $project, int $count, int $windowMinutes)
    {
        $rules = $project->alertRules()
            ->where('event_type', 'error_spike')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '🔥 Error Spike Detected!';
        $message = "Detected {$count} errors in the last {$windowMinutes} minutes.";
        $url = $project->dashboardUrl();
        $fields = [
            'Project' => $project->name,
            'Spike Count' => $count,
            'Time Window' => "{$windowMinutes}m",
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * The enabled rules of the given event type whose occurrence threshold and
     * time window are satisfied by the current state of the issue.
     *
     * @return Collection<int, AlertRule>
     */
    protected function rulesMatchingIssue(Project $project, string $eventType, Issue $issue)
    {
        $rules = $project->alertRules()
            ->where('event_type', $eventType)
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        if ($rules->isEmpty()) {
            return $rules;
        }

        $occurrencesInWindow = [];

        return $rules->filter(function (AlertRule $rule) use ($issue, &$occurrencesInWindow) {
            return $this->issueSatisfiesRule($rule, $issue, $occurrencesInWindow);
        });
    }

    /**
     * Decide whether an issue occurrence should trigger the rule.
     *
     * Settings:
     *  - `occurrence_threshold` (default 1): alert only after this many occurrences.
     *  - `time_window` (minutes, default 0): count occurrences within this window.
     *    With no window the issue's lifetime occurrences_count is used.
     *
     * A rule fires once when the threshold is crossed. With a window it may fire
     * again once the window has elapsed; without a window it fires only once
     * per issue.
     *
     * @param  array<int, int>  $occurrencesInWindow  Per-window occurrence counts, shared across rules to avoid repeated queries.
     */
    protected function issueSatisfiesRule(AlertRule $rule, Issue $issue, array &$occurrencesInWindow): bool
    {
        $settings = $rule->settings ?? [];
        $threshold = max(1, (int) ($settings['occurrence_threshold'] ?? 1));
        $windowMinutes = max(0, (int) ($settings['time_window'] ?? 0));

        if ($threshold === 1) {
            return $issue->wasRecentlyCreated;
        }

        if ($windowMinutes === 0) {
            $occurrences = (int) $issue->occurrences_count;
        } else {
            $occurrences = $occurrencesInWindow[$windowMinutes] ??= $issue->records()
                ->where('created_at', '>=', now()->subMinutes($windowMinutes))
                ->count();
        }

        if ($occurrences < $threshold) {
            return false;
        }

        $thresholdKey = "alert_rule_{$rule->id}_issue_{$issue->id}_threshold_reached";

        if (Cache::has($thresholdKey)) {
            return false;
        }

        if ($windowMinutes === 0) {
            Cache::forever($thresholdKey, true);
        } else {
            Cache::put($thresholdKey, true, now()->addMinutes($windowMinutes));
        }

        return true;
    }

    /**
     * Dispatch alert to all integrations of a rule.
     */
    protected function dispatchAlert(AlertRule $rule, string $title, string $message, array $fields = [], ?string $url = null)
    {
        $settings = $rule->settings ?? [];
        $throttlePeriod = $settings['throttle_period'] ?? 3600;

        $errorHash = md5($title.$message);
        $cacheKey = "alert_rule_{$rule->id}_{$errorHash}";

        $lastSentKey = "{$cacheKey}_last_sent";
        if ($throttlePeriod > 0 && Cache::has($lastSentKey)) {
            return;
        }

        if ($throttlePeriod > 0) {
            Cache::put($lastSentKey, true, now()->addSeconds($throttlePeriod));
        }

        foreach ($rule->integrations as $integration) {
            if (! $integration->is_enabled) {
                continue;
            }
            $this->integrationService->send($integration, $title, $message, $fields, $url);
        }
    }
}
