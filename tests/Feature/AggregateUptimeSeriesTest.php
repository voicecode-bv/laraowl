<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\RecordService;
use App\Support\TeamProjectScope;
use Illuminate\Support\Facades\DB;

/**
 * The "All" dashboard charts availability per application. Guards what the
 * series says, and what it costs to say it.
 */
function uptimeTeam(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return [$team, $user];
}

test('the legend carries one application per monitored project with its availability', function () {
    [$team] = uptimeTeam();

    $healthy = Project::factory()->create(['team_id' => $team->id, 'name' => 'Healthy', 'last_uptime_status' => 'up']);
    $flaky = Project::factory()->create(['team_id' => $team->id, 'name' => 'Flaky', 'last_uptime_status' => 'down']);

    $healthy->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subMinutes(2)],
        ['status' => 'up', 'response_time' => 300, 'status_code' => 200, 'checked_at' => now()->subMinutes(4)],
    ]);

    $flaky->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 200, 'status_code' => 200, 'checked_at' => now()->subMinutes(2)],
        ['status' => 'down', 'response_time' => null, 'status_code' => 500, 'checked_at' => now()->subMinutes(4)],
    ]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    // Least available first, so the application worth looking at leads.
    expect($series['projects'])->toHaveCount(2)
        ->and($series['projects'][0]['checks'])->toBe(2)
        ->and($series['projects'][0]['name'])->toBe('Flaky')
        ->and($series['projects'][0]['uptime'])->toBe(50.0)
        ->and($series['projects'][0]['down'])->toBe(1)
        // The average skips the down check, which recorded no response time.
        ->and($series['projects'][0]['avg_response_time'])->toBe(200)
        ->and($series['projects'][1]['name'])->toBe('Healthy')
        ->and($series['projects'][1]['uptime'])->toBe(100.0)
        ->and($series['projects'][1]['avg_response_time'])->toBe(200)
        ->and($series['omitted'])->toBe(0);
});

test('the series spans the whole period, and only the failed slots carry a bar', function () {
    [$team] = uptimeTeam();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $project->uptimeChecks()->createMany([
        ['status' => 'down', 'response_time' => null, 'status_code' => 503, 'checked_at' => now()->subMinutes(3)],
        ['status' => 'up', 'response_time' => 80, 'status_code' => 200, 'checked_at' => now()->subMinutes(2)],
    ]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    // One slot per minute of the hour, quiet ones included, so the chart
    // keeps its x-axis instead of collapsing onto the single failed minute.
    expect($series['series'])->toHaveCount(60);

    $withBars = collect($series['series'])->filter(fn (array $slot) => array_key_exists('failed_'.$project->id, $slot));

    // The minute that came through clean carries no keys at all, which is
    // what keeps a quiet period's payload small.
    expect($withBars)->toHaveCount(1)
        ->and($withBars->first()['failed_'.$project->id])->toBe(1)
        ->and($withBars->first()['checks_'.$project->id])->toBe(1);
});

test('the day view folds the checks onto the chart grid instead of one slot per minute', function () {
    [$team] = uptimeTeam();

    $project = Project::factory()->create(['team_id' => $team->id]);

    // Three checks inside one quarter hour: one slot, not three.
    $project->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->startOfHour()->addMinutes(1)],
        ['status' => 'down', 'response_time' => null, 'status_code' => 500, 'checked_at' => now()->startOfHour()->addMinutes(6)],
        ['status' => 'up', 'response_time' => 200, 'status_code' => 200, 'checked_at' => now()->startOfHour()->addMinutes(11)],
    ]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '24h');

    $withBars = collect($series['series'])->filter(fn (array $slot) => array_key_exists('failed_'.$project->id, $slot));

    expect($series['series'])->toHaveCount(96)
        ->and($withBars)->toHaveCount(1)
        ->and($withBars->first()['failed_'.$project->id])->toBe(1)
        // The bar is one failure out of the three checks the slot folded in.
        ->and($withBars->first()['checks_'.$project->id])->toBe(3)
        ->and($series['projects'][0]['uptime'])->toBe(66.67)
        ->and($series['projects'][0]['avg_response_time'])->toBe(150);
});

test('only monitored applications are charted, and the chart is capped', function () {
    [$team] = uptimeTeam();

    $unmonitored = Project::factory()->withoutUptimeMonitoring()->create(['team_id' => $team->id]);
    $unmonitored->uptimeChecks()->create(['status' => 'up', 'response_time' => 10, 'checked_at' => now()]);

    foreach (range(1, 14) as $index) {
        Project::factory()
            ->create(['team_id' => $team->id, 'name' => 'App '.$index])
            ->uptimeChecks()
            ->create([
                'status' => $index <= 13 ? 'down' : 'up',
                'response_time' => 50,
                'status_code' => 200,
                'checked_at' => now()->subMinute(),
            ]);
    }

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    expect($series['projects'])->toHaveCount(8)
        // What is left out is the healthy application and the outages past
        // the eighth — the chart keeps the least available ones.
        ->and($series['omitted'])->toBe(6)
        ->and(collect($series['projects'])->pluck('uptime')->unique()->all())->toBe([0.0])
        ->and(collect($series['projects'])->pluck('id')->contains($unmonitored->id))->toBeFalse();
});

test('a team with nothing monitored answers with an empty series', function () {
    [$team] = uptimeTeam();

    Project::factory()->withoutUptimeMonitoring()->create(['team_id' => $team->id]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    expect($series['projects'])->toBe([])
        ->and($series['series'])->toBe([])
        ->and($series['omitted'])->toBe(0);
});

test('the whole chart costs one read over the checks', function () {
    [$team] = uptimeTeam();

    foreach (range(1, 5) as $index) {
        Project::factory()
            ->create(['team_id' => $team->id])
            ->uptimeChecks()
            ->create(['status' => 'up', 'response_time' => 20, 'status_code' => 200, 'checked_at' => now()->subMinute()]);
    }

    // Resolved up front: the controller builds the scope once per request,
    // before any of the dashboard's reads.
    $scope = new TeamProjectScope($team);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(RecordService::class)->getUptimeSeries($scope, '24h');

    // One lookup for the monitored applications, one grouped read for every
    // line and every legend total on the chart.
    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'uptime_checks')))->toHaveCount(1)
        ->and($queries)->toHaveCount(2);
});

test('the aggregate dashboard defers the series, and a single project never asks for it', function () {
    [$team, $user] = uptimeTeam();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $project->uptimeChecks()->create(['status' => 'up', 'response_time' => 120, 'status_code' => 200, 'checked_at' => now()->subMinute()]);

    $this->actingAs($user);

    $this->get(route('dashboard', ['current_team' => $team->slug, 'project' => TeamProjectScope::SLUG]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/index')
            ->missing('uptime_series')
            ->loadDeferredProps('uptime', fn ($reload) => $reload
                ->has('uptime_series.projects', 1)
                ->where('uptime_series.projects.0.name', $project->name)
                ->has('uptime_series.series', 60)
            )
        );

    $this->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/index')
            ->missing('uptime_series')
            ->loadDeferredProps(fn ($reload) => $reload->missing('uptime_series'))
        );
});

test('a measured slot reports its check count even when nothing failed', function () {
    [$team] = uptimeTeam();

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Steady',
        'last_uptime_status' => 'up',
    ]);

    $project->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subMinutes(2)],
        ['status' => 'up', 'response_time' => 120, 'status_code' => 200, 'checked_at' => now()->subMinutes(2)],
    ]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    $measured = collect($series['series'])->filter(
        fn (array $slot) => isset($slot["checks_{$project->id}"]),
    );

    // The band needs "we looked and it was fine" to be distinguishable from
    // "we were not looking": a clean slot carries its count and no failures.
    expect($measured)->toHaveCount(1)
        ->and($measured->first()["checks_{$project->id}"])->toBe(2)
        ->and($measured->first())->not->toHaveKey("failed_{$project->id}");
});

test('a slot nothing was measured in carries no keys at all', function () {
    [$team] = uptimeTeam();

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Gappy',
        'last_uptime_status' => 'up',
    ]);

    $project->uptimeChecks()->create([
        'status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subMinutes(2),
    ]);

    $series = app(RecordService::class)->getUptimeSeries(new TeamProjectScope($team), '1h');

    $untouched = collect($series['series'])->reject(
        fn (array $slot) => isset($slot["checks_{$project->id}"]),
    );

    expect($untouched)->not->toBeEmpty()
        ->and($untouched->every(fn (array $slot) => ! isset($slot["failed_{$project->id}"])))->toBeTrue();
});
