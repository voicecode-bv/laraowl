<?php

use App\Models\Project;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;

function uptimeProject(): Project
{
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.com']);

    $project->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subMinutes(5)],
        ['status' => 'down', 'response_time' => 300, 'status_code' => 500, 'checked_at' => now()->subMinutes(30)],
        // Outside the hour, inside the day.
        ['status' => 'up', 'response_time' => 900, 'status_code' => 200, 'checked_at' => now()->subHours(5)],
    ]);

    return $project;
}

test('the uptime summary covers the selected period', function () {
    $stats = app(RecordService::class)->getUptimeStats(uptimeProject(), '1h')['uptime_stats'];

    expect($stats['total_checks'])->toBe(2)
        ->and($stats['uptime_percentage'])->toBe(50.0)
        ->and($stats['avg_response_time'])->toBe(200.0);

    $day = app(RecordService::class)->getUptimeStats(uptimeProject(), '24h')['uptime_stats'];

    // The older check only counts once the period reaches it.
    expect($day['total_checks'])->toBe(3)
        ->and($day['uptime_percentage'])->toBe(66.67);
});

test('the uptime summary reports the newest check as a timestamp', function () {
    $project = uptimeProject();
    $newest = $project->uptimeChecks()->orderByDesc('checked_at')->first();

    $stats = app(RecordService::class)->getUptimeStats($project, '24h')['uptime_stats'];

    expect($stats['last_check'])->toBe($newest->checked_at->toIso8601String());
});

test('the whole history stays available under the all period', function () {
    $stats = app(RecordService::class)->getUptimeStats(uptimeProject(), 'all')['uptime_stats'];

    expect($stats['total_checks'])->toBe(3);
});

test('the uptime summary is a single aggregate query', function () {
    $project = uptimeProject();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(RecordService::class)->getUptimeStats($project, '24h');

    // One page of checks (plus its count), and one aggregate for the summary —
    // not a count, a filtered count, an average and a sort over the table.
    $summaryQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'uptime_checks'));

    expect($summaryQueries)->toHaveCount(3)
        ->and($summaryQueries->filter(fn (string $sql) => str_contains(strtolower($sql), 'avg(')))->toHaveCount(1);
});

test('a project without uptime monitoring answers with an empty summary', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => false]);

    $stats = app(RecordService::class)->getUptimeStats($project, '24h');

    expect($stats['uptime_stats']['total_checks'])->toBe(0)
        ->and($stats['uptime_stats']['last_check'])->toBeNull()
        ->and($stats['checks']->total())->toBe(0);
});
