<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\UpdateService;
use App\Support\TeamProjectScope;
use App\Support\Version;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Resolved once per request: `currentTeam` and `currentProject` both need
     * the team behind the URL, and looking it up twice meant two identical
     * slug queries on every page load. Tied to the request the answer was
     * resolved for, so a reused middleware instance cannot serve a stale team.
     */
    private ?Team $resolvedTeam = null;

    private ?int $resolvedTeamRequest = null;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => function () use ($request, $user) {
                $team = $this->resolveTeam($request, $user);

                return $team ? $user?->toUserTeam($team) : null;
            },
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'projects' => function () use ($user) {
                if (! $user) {
                    return [];
                }

                // One round trip: the team ids stay a subquery rather than a
                // separate select whose result is only fed back in.
                return Project::query()
                    ->whereIn('team_id', $user->teams()->select('teams.id'))
                    ->get(['id', 'team_id', 'name', 'slug']);
            },
            'currentProject' => function () use ($request, $user) {
                $team = $this->resolveTeam($request, $user);

                $projectParam = $request->route('project');

                if ($projectParam === TeamProjectScope::SLUG) {
                    return [
                        'id' => null,
                        'team_id' => $team?->id,
                        'name' => 'All',
                        'slug' => TeamProjectScope::SLUG,
                        'is_aggregate' => true,
                    ];
                }

                if ($projectParam instanceof Project) {
                    return $projectParam;
                }

                if (is_string($projectParam)) {
                    return $team?->projects()->where('slug', $projectParam)->first();
                }

                return $team?->projects()->first();
            },
            'period' => fn () => $request->query('period', '1h'),
            'from' => fn () => $request->query('from'),
            'to' => fn () => $request->query('to'),
            'version' => Version::current(),
            'update' => fn () => $this->pendingUpdate($user),
            'flash' => [
                'mcpToken' => fn () => $request->session()->get('mcpToken'),
            ],
        ];
    }

    /**
     * The team the current URL belongs to, falling back to the user's own
     * current team.
     */
    protected function resolveTeam(Request $request, ?User $user): ?Team
    {
        if ($this->resolvedTeamRequest === spl_object_id($request)) {
            return $this->resolvedTeam;
        }

        $this->resolvedTeamRequest = spl_object_id($request);
        $param = $request->route('current_team');

        $team = match (true) {
            $param instanceof Team => $param,
            is_string($param) => Team::where('slug', $param)->first(),
            default => null,
        };

        return $this->resolvedTeam = $team ?: $user?->currentTeam;
    }

    /**
     * Get the release this instance should update to, for the operator.
     *
     * Only the instance operator is shown the update banner, since nobody else
     * can act on it. This reads from cache only and never blocks the request.
     *
     * @return array{version: string, name: string, url: string, notes: string|null, publishedAt: string|null}|null
     */
    protected function pendingUpdate(?User $user): ?array
    {
        if (! $user?->isInstanceOperator()) {
            return null;
        }

        return app(UpdateService::class)->pendingUpdate()?->toArray();
    }
}
