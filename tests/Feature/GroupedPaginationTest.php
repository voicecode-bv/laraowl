<?php

use App\Models\Project;
use App\Services\IngestService;
use App\Services\RecordService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * The users table and the top-N tables page over a grouped aggregate. Asking
 * `paginate()` for the total runs that aggregate a second time inside a
 * subquery; both are counted off the distinct keys instead.
 */
function ingestFor(Project $project, array $records): void
{
    app(IngestService::class)->ingest($project, $records);
}

function queriesDuring(Closure $callback): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

test('the users table knows its total without running the grouping twice', function () {
    $project = Project::factory()->create();

    ingestFor($project, collect(range(1, 25))
        ->map(fn (int $i) => ['t' => 'request', 'status_code' => 200, 'user' => (string) $i])
        ->all());

    $service = app(RecordService::class);

    $queries = queriesDuring(fn () => $service->getUserStats($project, '24h'));

    $users = $service->getUserStats($project, '24h')['users'];

    expect($users->total())->toBe(25)
        ->and($users->items())->toHaveCount(20)
        ->and($users->lastPage())->toBe(2)
        // A count over a derived table is how paginate() would have answered.
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'as aggregate_table')))->toBeEmpty()
        // Two distinct counts: the authenticated users on the overview card,
        // and the paginator's own total.
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'count(distinct "user_key")')))->toHaveCount(2);
});

test('the second page of the users table carries the rest', function () {
    $project = Project::factory()->create();

    ingestFor($project, collect(range(1, 25))
        ->map(fn (int $i) => ['t' => 'request', 'status_code' => 200, 'user' => (string) $i])
        ->all());

    Paginator::currentPageResolver(fn () => 2);
    $users = app(RecordService::class)->getUserStats($project, '24h')['users'];
    Paginator::currentPageResolver(fn () => 1);

    expect($users->total())->toBe(25)
        ->and($users->items())->toHaveCount(5);
});

test('a top-N table knows its total without running the grouping twice', function () {
    $project = Project::factory()->create();

    ingestFor($project, collect(range(1, 22))
        ->map(fn (int $i) => ['t' => 'request', '_group' => "route-{$i}", 'method' => 'GET', 'route_path' => "/p/{$i}", 'status_code' => 200, 'duration' => 10])
        ->all());

    $service = app(RecordService::class);

    $queries = queriesDuring(fn () => $service->getRequestStats($project, '24h'));

    $requests = $service->getRequestStats($project, '24h')['requests'];

    expect($requests->total())->toBe(22)
        ->and($requests->items())->toHaveCount(20)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'as aggregate_table')))->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'count(distinct "group_key")')))->toHaveCount(1);
});

test('an empty period reports an empty page', function () {
    $project = Project::factory()->create();

    $service = app(RecordService::class);

    expect($service->getUserStats($project, '24h')['users']->total())->toBe(0)
        ->and($service->getRequestStats($project, '24h')['requests']->total())->toBe(0);
});
