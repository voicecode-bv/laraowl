<?php

namespace App\Http\Controllers\Projects;

use App\Concerns\ResolvesProjectScope;
use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Models\Team;
use App\Services\RecordService;
use App\Support\ExceptionTrace;
use App\Support\ProjectContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RecordController extends Controller
{
    use ResolvesProjectScope;

    protected RecordService $recordService;

    public function __construct(RecordService $recordService)
    {
        $this->recordService = $recordService;
    }

    /**
     * Unified Entry Point for Monitoring Dashboards.
     *
     * `$project` is the raw route segment rather than a bound `Project` so it
     * can also be the reserved "All" slug (see `ResolvesProjectScope`). The
     * drill-down/detail actions below accept the same aggregate scope.
     */
    public function index(Request $request, Team $current_team, string $project): Response
    {
        $scope = $this->resolveProjectScope($current_team, $project);

        $routeName = $request->route()->getName();
        $period = $request->query('period', '1h');
        $from = $request->query('from');
        $to = $request->query('to');

        if ($routeName === 'dashboard') {
            return $this->renderDashboardIndex($scope, $period, $from, $to);
        }

        $method = 'render'.Str::studly(str_replace('-', '_', $routeName)).'Index';

        if (method_exists($this, $method)) {
            return $this->{$method}($scope, $period, $from, $to);
        }

        // Fallback for generic record lists (Logs, Cache, etc.)
        $type = $this->resolveTypeFromRoute($routeName);

        return Inertia::render($this->resolveComponentPath($routeName), [
            'records' => $this->recordService->getPaginatedRecords($scope, $type, $request->search, $period, $from, $to),
            'filters' => $request->only(['search', 'period', 'from', 'to']),
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Specialized Domain Renderers
     */
    /**
     * The dashboard renders in three parts.
     *
     * The cards come from one grouped read and are answered straight away;
     * the charts and the user panels are deferred, so the screen paints
     * before either has been queried. Each part resolves through a memoised
     * closure, which keeps two things true: a part nobody asked for costs
     * nothing (a deferred follow-up requests only its own group, so the
     * others are never resolved), and the two props inside a group share the
     * single read that produced them.
     */
    protected function renderDashboardIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        $summary = $this->memoize(fn () => $this->recordService->getDashboardSummary($project, $period, $from, $to));
        $charts = $this->memoize(fn () => $this->recordService->getDashboardCharts($project, $period, $from, $to));
        $panels = $this->memoize(fn () => $this->recordService->getDashboardUserPanels($project, $period, $from, $to));

        return Inertia::render('dashboard/index', [
            ...$this->lazyProps($summary, [
                'total_requests',
                'request_breakdown',
                'duration_stats',
                'total_exceptions',
                'job_stats',
                'auth_users_count',
                'guest_users_count',
                'uptime_status',
            ]),
            'timeSeries' => Inertia::defer(fn () => $charts()['timeSeries'], 'charts'),
            'exceptionTimeSeries' => Inertia::defer(fn () => $charts()['exceptionTimeSeries'], 'charts'),
            'impacted_users' => Inertia::defer(fn () => $panels()['impacted_users'], 'panels'),
            'active_users' => Inertia::defer(fn () => $panels()['active_users'], 'panels'),
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ]);
    }

    protected function renderRequestsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        $sort = request()->query('sort', 'total');
        $direction = request()->query('direction', 'desc');

        return $this->renderWithStats(
            'projects/requests',
            $this->recordService->getRequestStats($project, $period, $from, $to, $sort, $direction),
            $project,
            $period,
            $from,
            $to,
            // The only screen that renders the cross-type record counts.
            withQuickStats: true,
        );
    }

    protected function renderUsersIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/users', $this->recordService->getUserStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderJobsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/jobs', $this->recordService->getJobStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderExceptionsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/exceptions', $this->recordService->getExceptionStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderCommandsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/commands', $this->recordService->getCommandStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderQueriesIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/queries', $this->recordService->getQueryStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderScheduledTasksIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/scheduled-tasks', $this->recordService->getScheduledTaskStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderNotificationsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/notifications', $this->recordService->getNotificationStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderMailIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/mail', $this->recordService->getMailStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderOutgoingRequestsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/outgoing-requests', $this->recordService->getOutgoingRequestStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderUptimeIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/uptime', $this->recordService->getUptimeStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderSecurityIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/security', $this->recordService->getSecurityStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderCacheIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return $this->renderWithStats('projects/cache', $this->recordService->getCacheStats($project, $period, $from, $to), $project, $period, $from, $to);
    }

    protected function renderLogsIndex(ProjectContext $project, string $period, ?string $from, ?string $to): Response
    {
        return Inertia::render('projects/logs/index', [
            'records' => $this->recordService->getLogRecords($project, request('search'), $period, $from, $to),
            'filters' => request()->only(['search', 'period', 'from', 'to']),
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Drill-down Detail Handlers (Hashed)
     *
     * `$project` is the raw route segment, not a bound `Project`, so these
     * also work under the "All" aggregate slug: the history shown is every
     * matching record across the team's projects for that hash — consistent
     * with the list pages, which already merge rows by hash across projects
     * (see `RecordService::groupList()`) rather than keeping them separate.
     */
    public function showDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/requests/show');
    }

    public function showJobDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/jobs/show');
    }

    public function showExceptionDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/exceptions/show');
    }

    public function showQueryDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/queries/show');
    }

    public function showCommandDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/commands/show');
    }

    public function showScheduledTaskDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/scheduled-tasks/show');
    }

    public function showNotificationDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/notifications/show');
    }

    public function showMailDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/mail/show');
    }

    public function showOutgoingRequestDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        return $this->renderHistory($this->resolveProjectScope($current_team, $project), $hash, 'projects/outgoing-requests/show');
    }

    public function showUserDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        $scope = $this->resolveProjectScope($current_team, $project);
        $period = $request->query('period', '1h');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('projects/users/show', $this->recordService->getUserHistory($scope, $hash, $period, $from, $to));
    }

    public function showSecurityDetails(Request $request, Team $current_team, string $project, string $hash): Response
    {
        $scope = $this->resolveProjectScope($current_team, $project);
        $period = $request->query('period', '1h');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('projects/security/show', $this->recordService->getSecurityHistoryByHash($scope, $hash, $period, $from, $to));
    }

    /**
     * Helpers
     */
    protected function renderHistory(ProjectContext $project, string $hash, string $component): Response
    {
        $period = request()->query('period', '1h');
        $from = request()->query('from');
        $to = request()->query('to');
        $history = $this->recordService->getHistoryByHash($project, $hash, $period, $from, $to);

        // Find the best record to use for meta (one that has identifying info)
        $first = $history->firstWhere(function ($r) {
            return ! empty($r->payload['name']) ||
                   ! empty($r->payload['job']) ||
                   ! empty($r->payload['command']) ||
                   ! empty($r->payload['route_path']) ||
                   ! empty($r->payload['class']);
        }) ?: $history->first();

        return Inertia::render($component, [
            'hash' => $hash,
            'meta' => $first ? ExceptionTrace::normalize($first->payload) : [],
            'records' => $history,
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Resolve a value at most once, however many props read it.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $resolver
     * @return Closure(): TValue
     */
    protected function memoize(Closure $resolver): Closure
    {
        $resolved = null;

        return function () use ($resolver, &$resolved) {
            return $resolved ??= $resolver();
        };
    }

    /**
     * Spread part of a payload across props that each resolve on demand, so
     * a request that does not ask for them never pays for the read behind
     * them.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     * @param  list<string>  $keys
     * @return array<string, Closure(): mixed>
     */
    protected function lazyProps(Closure $resolver, array $keys): array
    {
        $props = [];

        foreach ($keys as $key) {
            $props[$key] = fn () => $resolver()[$key];
        }

        return $props;
    }

    /**
     * `$withQuickStats` is opt-in because `getQuickStats()` aggregates every
     * record type in one go: paying for it on the screens that never render
     * it is an aggregate query per page view for nothing.
     */
    protected function renderWithStats(string $component, array $data, ProjectContext $project, string $period, ?string $from = null, ?string $to = null, bool $withQuickStats = false): Response
    {
        return Inertia::render($component.'/index', array_merge($data, [
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ], $withQuickStats ? [
            'stats' => $this->recordService->getQuickStats($project, $period, $from, $to),
        ] : []));
    }

    protected function resolveTypeFromRoute(string $routeName): string
    {
        $map = ['dashboard' => 'request', 'users' => 'user', 'security' => 'security'];

        return $map[$routeName] ?? Str::singular($routeName);
    }

    protected function resolveComponentPath(string $routeName): string
    {
        return $routeName === 'dashboard' ? 'dashboard' : 'projects/'.$routeName.'/index';
    }

    public function showOccurrence(Team $current_team, string $project, Record $record): Response
    {
        $scope = $this->resolveProjectScope($current_team, $project);

        // The record is bound by global id, so verify it belongs to a project
        // in scope for the URL — otherwise any member of any team could read
        // another team's telemetry by guessing record ids.
        abort_if(! in_array($record->project_id, $scope->projectIds(), true), 404);

        $record->load('issue');
        $relatedRecords = [];
        $traceId = $record->trace_id ?? ($record->payload['trace_id'] ?? null);

        if ($traceId) {
            $relatedRecords = Record::query()
                ->whereIn('project_id', $scope->projectIds())
                ->where('trace_id', $traceId)
                ->where('id', '!=', $record->id)
                ->orderBy('created_at')
                ->limit(200)
                ->get(['id', 'type', 'payload', 'created_at']);
        }

        return Inertia::render('projects/records/show', [
            'record' => $record,
            'relatedRecords' => $relatedRecords,
        ]);
    }
}
