<?php

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Models\Project;
use App\Services\AlertService;
use App\Services\IntegrationService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CheckProjectUptime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:check-health';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Perform uptime and heartbeat health checks for all projects';

    /**
     * Execute the console command.
     */
    /**
     * Maximum number of uptime requests in flight at the same time.
     */
    protected const CONCURRENCY = 25;

    /**
     * Execute the console command.
     *
     * The scheduler runs this command every 30 seconds, so a single run only
     * performs one round of checks and must finish well within that window.
     */
    public function handle(AlertService $alertService): int
    {
        $this->performUptimeChecks($alertService);
        $this->checkHeartbeats($alertService);

        $this->info('Health checks completed.');

        return self::SUCCESS;
    }

    protected function performUptimeChecks(AlertService $alertService): void
    {
        $projects = Project::withUptimeMonitoring()->get()->filter(function (Project $project) {
            if (is_null($project->last_uptime_check_at)) {
                return true;
            }

            return $project->last_uptime_check_at->addSeconds($project->uptime_check_interval)->isPast();
        });

        foreach ($projects->chunk(self::CONCURRENCY) as $batch) {
            $batch = $batch->values();
            $start = microtime(true);

            $responses = Http::pool(fn (Pool $pool) => $batch->map(
                fn (Project $project) => $pool->retry(2, 1000, throw: false)->timeout(10)->get($project->url)
            )->all());

            foreach ($batch as $index => $project) {
                $this->recordUptimeResult($project, $responses[$index], $start, $alertService);
            }
        }
    }

    protected function recordUptimeResult(Project $project, Response|\Throwable $response, float $start, AlertService $alertService): void
    {
        $status = 'up';
        $statusCode = 0;
        $error = null;

        if ($response instanceof \Throwable) {
            $status = 'down';
            $error = $response->getMessage();
        } else {
            $statusCode = $response->status();

            if ($response->failed()) {
                $status = 'down';
                $error = "HTTP error status: {$statusCode}";
            }
        }

        $responseTime = round((microtime(true) - $start) * 1000); // in ms

        // Record the check
        $project->uptimeChecks()->create([
            'status' => $status,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'error' => $error,
            'checked_at' => now(),
        ]);

        $previousStatus = $project->last_uptime_status;

        // Update project state
        $project->update([
            'last_uptime_check_at' => now(),
            'last_uptime_status' => $status,
        ]);

        // Trigger Alert if status changed to 'down'
        if ($status === 'down' && $previousStatus !== 'down') {
            $this->warn("Project {$project->name} is DOWN!");
            $alertService->notifyUptimeDown($project, $statusCode, $error);
        }

        // Trigger Alert if status recovered to 'up'
        if ($status === 'up' && $previousStatus === 'down') {
            $this->info("Project {$project->name} is back UP.");
            $this->notifyRecovery($project, $alertService);
        }
    }

    protected function checkHeartbeats(AlertService $alertService): void
    {
        $failingHeartbeats = Heartbeat::where('status', 'active')
            ->get()
            ->filter(fn ($h) => $h->isFailing());

        foreach ($failingHeartbeats as $heartbeat) {
            $heartbeat->update(['status' => 'failing']);
            $alertService->notifyHeartbeatFailed($heartbeat);
            $this->warn("Heartbeat '{$heartbeat->name}' for project '{$heartbeat->project->name}' failed.");
        }
    }

    protected function notifyRecovery(Project $project, AlertService $alertService): void
    {
        $rules = $project->alertRules()
            ->where('event_type', 'uptime_down')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        foreach ($rules as $rule) {
            foreach ($rule->integrations as $integration) {
                if (! $integration->is_enabled) {
                    continue;
                }

                app(IntegrationService::class)->send(
                    $integration,
                    "✅ Project Back Online: {$project->name}",
                    'Your project is responding correctly again.',
                    [
                        'Project' => $project->name,
                        'URL' => $project->url,
                        'Status' => 'UP',
                    ],
                    $project->dashboardUrl()
                );
            }
        }
    }
}
