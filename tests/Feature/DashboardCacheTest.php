<?php

use App\Models\Project;
use App\Services\IngestService;
use App\Services\RecordService;
use App\Support\RollupCache;
use Illuminate\Support\Facades\DB;

function rollupQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'record_rollups')) {
            $queries++;
        }
    });

    $callback();

    return $queries;
}

test('the dashboard cache stays out of the way of a store the workers do not share', function (string $driver, bool $expected) {
    config(['cache.default' => $driver]);

    expect(app(RollupCache::class)->enabled())->toBe($expected);
})->with([
    // A cache read on the database store is another query against the same
    // database the aggregate came from.
    ['database', false],
    ['file', false],
    ['array', false],
    ['redis', true],
    ['memcached', true],
]);

test('an operator can decide for themselves either way', function () {
    config(['cache.default' => 'redis', 'laraowl.dashboard_cache.enabled' => false]);
    expect(app(RollupCache::class)->enabled())->toBeFalse();

    config(['cache.default' => 'array', 'laraowl.dashboard_cache.enabled' => true]);
    expect(app(RollupCache::class)->enabled())->toBeTrue();

    // A time to live of nothing is off, however it was switched on.
    config(['laraowl.dashboard_cache.ttl' => 0]);
    expect(app(RollupCache::class)->enabled())->toBeFalse();
});

test('an enabled cache answers a repeated dashboard read without querying again', function () {
    config(['laraowl.dashboard_cache.enabled' => true, 'cache.default' => 'array']);

    $project = Project::factory()->create();
    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => '1'],
    ]);

    $service = app(RecordService::class);

    $first = rollupQueries(fn () => $service->getDashboardSummary($project, '24h'));
    $second = rollupQueries(fn () => $service->getDashboardSummary($project, '24h'));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and($service->getDashboardSummary($project, '24h')['total_requests'])->toBe(1);
});

test('a cached answer belongs to one scope and one period', function () {
    config(['laraowl.dashboard_cache.enabled' => true, 'cache.default' => 'array']);

    $project = Project::factory()->create();
    $other = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
    ]);

    $service = app(RecordService::class);

    expect($service->getDashboardSummary($project, '24h')['total_requests'])->toBe(2)
        // A different period is a different question...
        ->and($service->getDashboardSummary($project, '1h')['total_requests'])->toBe(2)
        // ...and so is a different project, which must never read the first
        // one's answer.
        ->and($service->getDashboardSummary($other, '24h')['total_requests'])->toBe(0)
        ->and($service->getQuickStats($other, '24h')['requests'])->toBe(0)
        ->and($service->getQuickStats($project, '24h')['requests'])->toBe(2);
});

test('a disabled cache resolves every read', function () {
    config(['laraowl.dashboard_cache.enabled' => false]);

    $project = Project::factory()->create();
    app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200]]);

    $service = app(RecordService::class);

    $first = rollupQueries(fn () => $service->getDashboardCharts($project, '24h'));
    $second = rollupQueries(fn () => $service->getDashboardCharts($project, '24h'));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe($first);
});
