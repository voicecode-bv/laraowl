<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\IngestService;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;

/**
 * Guards the work a dashboard page view is allowed to cost: props nobody
 * renders are props nobody should pay a query for.
 */
function dashboardActor(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $project];
}

test('the cross-type record counts are only computed for the screen that renders them', function () {
    [$user, $team, $project] = dashboardActor();

    $this->actingAs($user);

    $route = fn (string $name) => route($name, ['current_team' => $team->slug, 'project' => $project->slug]);

    $this->get($route('requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats'));

    foreach (['dashboard', 'exceptions', 'queries', 'jobs', 'logs'] as $name) {
        $this->get($route($name))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('stats'));
    }
});

test('the dashboard does not carry an issue list it never renders', function () {
    [$user, $team, $project] = dashboardActor();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('recent_issues'));
});

test('the dashboard reads each rollup table once per shape it needs', function () {
    [$user, $team, $project] = dashboardActor();

    app(IngestService::class)->ingest($project, [
        ['t' => 'user', 'id' => 7, 'name' => 'Ada Lovelace', 'username' => 'ada@example.com'],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => 7],
        ['t' => 'exception', 'class' => 'E', 'message' => 'm', 'user' => 7],
        ['t' => 'queued-job', 'name' => 'J'],
        ['t' => 'job-attempt', 'name' => 'J', 'status' => 'processed'],
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(RecordService::class)->getDashboardStats($project, '24h');

    // Totals for requests, exceptions and jobs come out of one grouped read,
    // and the request and exception series share one read per table.
    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'record_rollups')))->toHaveCount(2)
        // The series slot, the two top-five panels and the distinct count; a
        // top-N per type cannot share a read without a window function.
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'record_user_buckets')))->toHaveCount(4)
        // One name/email lookup for both user panels, not one per panel.
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"records"')))->toHaveCount(2)
        ->and($queries)->toHaveCount(8);
});

test('a dashboard group with no records still reports zeroed totals', function () {
    [$user, $team, $project] = dashboardActor();

    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
    ]);

    $stats = app(RecordService::class)->getDashboardStats($project, '24h');

    // Grouping the totals means a type absent from the period has no row to
    // read; the cards still need a number.
    expect($stats['total_requests'])->toBe(1)
        ->and($stats['total_exceptions'])->toBe(0)
        ->and($stats['job_stats']['total'])->toBe(0)
        ->and($stats['job_stats']['avg_duration'])->toBe(0.0)
        ->and($stats['job_stats']['p95_duration'])->toBe(0.0);
});

test('the dashboard answers with its cards and defers the charts and the user panels', function () {
    [$user, $team, $project] = dashboardActor();

    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => 7],
        ['t' => 'exception', 'class' => 'E', 'message' => 'm', 'user' => 7],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The cards are there on the first paint.
            ->where('total_requests', 1)
            ->where('total_exceptions', 1)
            ->has('job_stats')
            ->has('uptime_status')
            // The charts and the panels are announced, not resolved.
            ->missing('timeSeries')
            ->missing('exceptionTimeSeries')
            ->missing('impacted_users')
            ->missing('active_users')
            ->loadDeferredProps('charts', fn ($reload) => $reload
                ->has('timeSeries')
                ->has('exceptionTimeSeries')
                // Each group travels on its own request.
                ->missing('impacted_users')
            )
            ->loadDeferredProps('panels', fn ($reload) => $reload
                ->has('impacted_users')
                ->has('active_users')
                ->missing('timeSeries')
            )
        );
});

test('the first dashboard request does not query what it defers', function () {
    [$user, $team, $project] = dashboardActor();

    app(IngestService::class)->ingest($project, [
        ['t' => 'user', 'id' => 7, 'name' => 'Ada Lovelace', 'username' => 'ada@example.com'],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => 7],
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk();

    // The grouped totals and the distinct-user count, and nothing else: the
    // series and the name lookups belong to the deferred groups, and a prop
    // nobody asked for must not resolve.
    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'record_rollups')))->toHaveCount(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'record_user_buckets')))->toHaveCount(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"records"')))->toHaveCount(0);
});

test('the shared team props cost one query regardless of how many teams a user has', function () {
    [$user] = dashboardActor();

    foreach (range(1, 4) as $ignored) {
        Team::factory()->create()->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    $user->refresh();
    $teamCount = $user->teams()->count();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $teams = $user->toUserTeams(includeCurrent: true);

    // The teams themselves, and nothing per team on top of that.
    expect($teams)->toHaveCount($teamCount)
        ->and($queries)->toBe(1)
        ->and($teams->first()->role)->not->toBeNull();
});
