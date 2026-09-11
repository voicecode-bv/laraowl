<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Record;
use App\Models\Team;
use App\Models\User;
use App\Services\IngestService;
use App\Services\RecordService;
use App\Support\TeamProjectScope;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The "All" scope lists records from several projects at once. Each project
 * has its own index, so the page is merged out of per-project reads rather
 * than left to the database to sort.
 */
function aggregateScope(int $projects = 3): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $created = collect(range(1, $projects))
        ->map(fn (int $i) => Project::factory()->create(['team_id' => $team->id, 'name' => "P{$i}"]));

    return [$team, $user, $created];
}

/**
 * Ingested rather than inserted: the listing reads its total off the rollups,
 * which only live ingestion writes.
 */
function seedRequests(Project $project, array $minutesAgo): void
{
    $base = Carbon::now();

    foreach ($minutesAgo as $minutes) {
        Carbon::setTestNow($base->copy()->subMinutes($minutes));

        app(IngestService::class)->ingest($project, [
            ['t' => 'request', 'status_code' => 200, 'project' => $project->name, 'age' => $minutes],
        ]);
    }

    Carbon::setTestNow($base);
}

test('the aggregate list interleaves the projects by recency', function () {
    [$team, $user, $projects] = aggregateScope();

    // Interleaved on purpose: the merge has to pick across projects, not
    // return one project's page and then the next.
    seedRequests($projects[0], [1, 6, 11]);
    seedRequests($projects[1], [2, 5, 12]);
    seedRequests($projects[2], [3, 4, 13]);

    $scope = new TeamProjectScope($team->fresh());
    $page = app(RecordService::class)->getPaginatedRecords($scope, 'request', null, '1h');

    expect($page->total())->toBe(9)
        ->and($page->items())->toHaveCount(9)
        ->and(collect($page->items())->map(fn (Record $r) => $r->payload['age'])->all())
        ->toBe([1, 2, 3, 4, 5, 6, 11, 12, 13]);
});

test('paging the aggregate list neither repeats nor skips a record', function () {
    [$team, $user, $projects] = aggregateScope();

    foreach ($projects as $project) {
        // Every project shares the same timestamps, which is what makes a
        // tie-break necessary for a stable page boundary.
        seedRequests($project, range(1, 20));
    }

    $scope = new TeamProjectScope($team->fresh());
    $service = app(RecordService::class);

    $firstPage = $service->getPaginatedRecords($scope, 'request', null, '1h');

    $this->app['request']->merge(['page' => 2]);
    Paginator::currentPageResolver(fn () => 2);
    $secondPage = $service->getPaginatedRecords($scope, 'request', null, '1h');
    Paginator::currentPageResolver(fn () => 1);

    $firstIds = collect($firstPage->items())->pluck('id');
    $secondIds = collect($secondPage->items())->pluck('id');

    expect($firstPage->total())->toBe(60)
        ->and($firstIds)->toHaveCount(50)
        ->and($secondIds)->toHaveCount(10)
        ->and($firstIds->intersect($secondIds))->toBeEmpty()
        // The two pages together are every record in scope, each once.
        ->and($firstIds->merge($secondIds)->unique())->toHaveCount(60);
});

test('a single project is read straight off its index', function () {
    [$team, $user, $projects] = aggregateScope(1);

    seedRequests($projects[0], [1, 2, 3]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $page = app(RecordService::class)->getPaginatedRecords($projects[0], 'request', null, '1h');

    // No merge to do, so no union and no second read to hydrate the page.
    expect($page->items())->toHaveCount(3)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'union')))->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"records"')))->toHaveCount(1);
});

test('the aggregate list merges per project instead of sorting every match', function () {
    [$team, $user, $projects] = aggregateScope();

    foreach ($projects as $project) {
        seedRequests($project, [1, 2, 3]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(RecordService::class)->getPaginatedRecords(new TeamProjectScope($team->fresh()), 'request', null, '1h');

    $union = collect($queries)->first(fn (string $sql) => str_contains($sql, 'union all'));

    expect($union)->not->toBeNull()
        // One bounded read per project...
        ->and(substr_count($union, 'union all'))->toBe(2)
        ->and(substr_count($union, '"project_id" = ?'))->toBe(3)
        // ...and then the page itself, hydrated by the ids that won.
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"records"."id" in (')))->toHaveCount(1);
});
