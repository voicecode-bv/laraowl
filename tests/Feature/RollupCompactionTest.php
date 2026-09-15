<?php

use App\Models\Project;
use App\Models\RecordDailyRollup;
use App\Models\RecordGroupDailyRollup;
use App\Models\RecordRollup;
use App\Models\RecordUserDailyBucket;
use App\Models\UptimeDailyRollup;
use App\Services\IngestService;
use App\Services\RecordService;
use App\Services\RollupCompactor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Ingest a batch as if it had arrived at a given moment, so a test can lay
 * out several days of history without waiting for them.
 */
function ingestAt(Project $project, string $at, array $records): void
{
    Date::setTestNow($at);
    app(IngestService::class)->ingest($project, $records);
    Date::setTestNow();
}

function foldRollups(Project $project): int
{
    return app(RollupCompactor::class)->compact($project->fresh());
}

function compactionRecordService(): RecordService
{
    return app(RecordService::class);
}

test('a pass folds every minute bucket of a day into one daily row', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(3)->setTime(2, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
        ['t' => 'request', 'status_code' => 500, 'duration' => 30],
    ]);
    ingestAt($project, now()->subDays(3)->setTime(21, 30)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 404, 'duration' => 50],
    ]);

    expect(RecordRollup::where('type', 'request')->count())->toBe(2);

    foldRollups($project);

    $day = RecordDailyRollup::where('type', 'request')->sole();

    expect($day->count)->toBe(3)
        ->and($day->ok_count)->toBe(1)
        ->and($day->client_error_count)->toBe(1)
        ->and($day->server_error_count)->toBe(1)
        ->and($day->sum_duration)->toBe(90.0)
        ->and($day->count_duration)->toBe(3)
        ->and($day->max_duration)->toBe(50.0)
        ->and($day->min_duration)->toBe(10.0)
        ->and($day->bucket->toDateTimeString())->toBe(now()->subDays(3)->startOfDay()->toDateTimeString());
});

test('re-running a pass rebuilds a day rather than adding to it', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(9, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
    ]);

    foldRollups($project);
    foldRollups($project);
    foldRollups($project);

    expect(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(2)
        ->and(RecordDailyRollup::where('type', 'request')->count())->toBe(1);
});

test('the daily grain carries the latency histogram, so percentiles survive the fold', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(1, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'duration' => 5],
        ['t' => 'request', 'status_code' => 200, 'duration' => 900],
    ]);

    foldRollups($project);

    $day = RecordDailyRollup::where('type', 'request')->sole();

    expect((int) $day->lat_le_1000)->toBe(2);
});

test('a day of hourly user buckets folds to one row per user', function () {
    $project = Project::factory()->create();
    $day = now()->subDays(2);

    ingestAt($project, $day->copy()->setTime(3, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'user' => 'alice'],
        ['t' => 'request', 'status_code' => 500, 'user' => 'alice'],
    ]);
    ingestAt($project, $day->copy()->setTime(18, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'user' => 'alice'],
        ['t' => 'request', 'status_code' => 200, 'user' => 'bob'],
    ]);

    foldRollups($project);

    $rows = RecordUserDailyBucket::where('type', 'request')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows->firstWhere('user_key', 'alice')->count)->toBe(3)
        ->and((int) $rows->firstWhere('user_key', 'alice')->error_count)->toBe(1)
        ->and((int) $rows->firstWhere('user_key', 'bob')->count)->toBe(1);
});

test('group rollups keep their labels through the fold', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(4, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'method' => 'POST', 'route_path' => '/checkout'],
    ]);

    foldRollups($project);

    $group = RecordGroupDailyRollup::where('type', 'request')->sole();

    expect($group->label)->toBe('POST')
        ->and($group->sublabel)->toBe('/checkout')
        ->and((int) $group->count)->toBe(1);
});

test('the long periods read the daily grain once a pass has run', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
    ]);

    foldRollups($project);
    $project->refresh();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $stats = compactionRecordService()->getQuickStats($project, '7d');

    expect($stats['requests'])->toBe(2)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'record_daily_rollups')))->not->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "record_rollups"')))->toBeEmpty();
});

test('the long periods fall back to the fine grain until a pass has run', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10],
    ]);

    expect($project->rollups_compacted_through)->toBeNull()
        ->and(compactionRecordService()->getQuickStats($project, '7d')['requests'])->toBe(2);
});

test('a scope whose compaction has fallen behind reads the fine grain', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(3)->setTime(12, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);

    // The schedule stopped running three days ago; the daily grain is missing
    // days the period covers, so the numbers have to come from the source.
    $project->forceFill(['rollups_compacted_through' => now()->subDays(3)->startOfDay()])->save();
    RecordDailyRollup::query()->delete();

    expect(compactionRecordService()->getQuickStats($project->fresh(), '7d')['requests'])->toBe(1);
});

test('the fine grain and the daily grain agree on a period', function () {
    $project = Project::factory()->create();

    foreach ([5, 3, 1] as $daysAgo) {
        ingestAt($project, now()->subDays($daysAgo)->setTime(10, 0)->toDateTimeString(), [
            ['t' => 'request', 'status_code' => 200, 'duration' => 20, 'user' => 'alice'],
            ['t' => 'request', 'status_code' => 500, 'duration' => 40, 'user' => 'bob'],
            ['t' => 'exception', 'class' => 'E', 'message' => 'm', 'user' => 'alice'],
        ]);
    }

    $beforeCompaction = compactionRecordService()->getDashboardStats($project, '7d');

    foldRollups($project);

    $afterCompaction = compactionRecordService()->getDashboardStats($project->fresh(), '7d');

    expect($afterCompaction['total_requests'])->toBe($beforeCompaction['total_requests'])
        ->and($afterCompaction['request_breakdown'])->toBe($beforeCompaction['request_breakdown'])
        ->and($afterCompaction['duration_stats'])->toBe($beforeCompaction['duration_stats'])
        ->and($afterCompaction['timeSeries'])->toBe($beforeCompaction['timeSeries']);
});

test('records arriving late wind the watermark back so their day is folded again', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(8, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);

    expect(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(1);

    $watermark = $project->fresh()->rollups_compacted_through;

    // Reloaded the way a queued ingest gets it, so it carries the watermark
    // the pass just wrote.
    ingestAt($project->fresh(), now()->subDays(2)->setTime(9, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    expect($project->fresh()->rollups_compacted_through)->toBeLessThan($watermark);

    foldRollups($project);

    expect(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(2);
});

test('ordinary ingest leaves the watermark alone', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(8, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);
    $watermark = $project->fresh()->rollups_compacted_through;

    app(IngestService::class)->ingest($project->fresh(), [['t' => 'request', 'status_code' => 200]]);

    expect($project->fresh()->rollups_compacted_through->toDateTimeString())
        ->toBe($watermark->toDateTimeString());
});

test('a day whose source buckets have been pruned keeps its daily row', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(4)->setTime(8, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);

    RecordRollup::query()->delete();
    app(RollupCompactor::class)->compact($project->fresh(), now()->subDays(6));

    expect(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(1);
});

test('the current day is folded but never marked complete', function () {
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200]]);

    foldRollups($project);

    expect(RecordDailyRollup::where('bucket', now()->startOfDay())->sum('count'))->toBe(1)
        ->and($project->fresh()->rollups_compacted_through->toDateTimeString())
        ->toBe(now()->startOfDay()->subDay()->toDateTimeString());
});

test('a project with no rollups at all is still marked as folded', function () {
    $project = Project::factory()->create();

    expect(foldRollups($project))->toBe(0)
        ->and($project->fresh()->rollups_compacted_through)->not->toBeNull();
});

test('the command folds every project', function () {
    $first = Project::factory()->create();
    $second = Project::factory()->create();

    ingestAt($first, now()->subDays(2)->setTime(8, 0)->toDateTimeString(), [['t' => 'request', 'status_code' => 200]]);
    ingestAt($second, now()->subDays(2)->setTime(8, 0)->toDateTimeString(), [['t' => 'exception', 'class' => 'E', 'message' => 'm']]);

    $this->artisan('laraowl:rollups:compact')->assertSuccessful();

    expect(RecordDailyRollup::where('project_id', $first->id)->where('type', 'request')->sum('count'))->toBe(1)
        ->and(RecordDailyRollup::where('project_id', $second->id)->where('type', 'exception')->sum('count'))->toBe(1);
});

test('the daily grain is pruned on its own, longer retention', function () {
    $project = Project::factory()->create([
        'rollup_retention_days' => 7,
        'daily_rollup_retention_days' => 400,
    ]);

    ingestAt($project, now()->subDays(30)->setTime(8, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);

    $this->artisan('model:prune', ['--model' => [RecordRollup::class, RecordDailyRollup::class]])->assertSuccessful();

    expect(RecordRollup::count())->toBe(0)
        ->and(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(1);
});

test('a day of uptime checks folds into one row', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.test']);
    $day = now()->subDays(2);

    $project->uptimeChecks()->createMany([
        ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => $day->copy()->setTime(0, 30)],
        ['status' => 'down', 'response_time' => null, 'status_code' => 500, 'checked_at' => $day->copy()->setTime(12, 0)],
        ['status' => 'up', 'response_time' => 300, 'status_code' => 200, 'checked_at' => $day->copy()->setTime(23, 45)],
    ]);

    foldRollups($project);

    $folded = UptimeDailyRollup::sole();

    expect((int) $folded->checks)->toBe(3)
        ->and((int) $folded->up_checks)->toBe(2)
        ->and((float) $folded->sum_response_time)->toBe(400.0)
        ->and((int) $folded->response_count)->toBe(2)
        ->and((int) $folded->max_response_time)->toBe(300)
        ->and((int) $folded->min_response_time)->toBe(100)
        ->and($folded->bucket->toDateTimeString())->toBe($day->copy()->startOfDay()->toDateTimeString())
        ->and($folded->last_checked_at->toDateTimeString())->toBe($day->copy()->setTime(23, 45)->toDateTimeString());
});

test('a day the project was not being checked on writes no uptime row', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.test']);

    $project->uptimeChecks()->create([
        'status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subDays(1)->setTime(9, 0),
    ]);

    app(RollupCompactor::class)->compact($project->fresh(), now()->subDays(5));

    expect(UptimeDailyRollup::count())->toBe(1);
});

test('the uptime summary and chart agree across the two grains', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.test']);

    foreach ([5, 3, 1] as $daysAgo) {
        $project->uptimeChecks()->createMany([
            ['status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subDays($daysAgo)->setTime(4, 0)],
            ['status' => 'down', 'response_time' => null, 'status_code' => 500, 'checked_at' => now()->subDays($daysAgo)->setTime(16, 0)],
            ['status' => 'up', 'response_time' => 300, 'status_code' => 200, 'checked_at' => now()->subDays($daysAgo)->setTime(20, 0)],
        ]);
    }

    $rawStats = compactionRecordService()->getUptimeStats($project, '7d')['uptime_stats'];
    $rawSeries = compactionRecordService()->getUptimeSeries($project, '7d');

    foldRollups($project);

    $foldedStats = compactionRecordService()->getUptimeStats($project->fresh(), '7d')['uptime_stats'];
    $foldedSeries = compactionRecordService()->getUptimeSeries($project->fresh(), '7d');

    expect($foldedStats)->toBe($rawStats)
        ->and($foldedSeries['series'])->toBe($rawSeries['series'])
        ->and($foldedSeries['projects'])->toBe($rawSeries['projects'])
        ->and($foldedStats['total_checks'])->toBe(9)
        ->and($foldedStats['uptime_percentage'])->toBe(66.67)
        ->and($foldedStats['avg_response_time'])->toBe(200.0);
});

test('the uptime chart reads the folded days once a pass has run', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.test']);

    $project->uptimeChecks()->create([
        'status' => 'down', 'response_time' => null, 'status_code' => 500, 'checked_at' => now()->subDays(2)->setTime(9, 0),
    ]);

    foldRollups($project);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    compactionRecordService()->getUptimeSeries($project->fresh(), '30d');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'uptime_daily_rollups')))->not->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "uptime_checks"')))->toBeEmpty();
});

test('an uptime-only project is folded even though it has no telemetry', function () {
    $project = Project::factory()->create(['uptime_monitoring_enabled' => true, 'url' => 'https://example.test']);

    $project->uptimeChecks()->create([
        'status' => 'up', 'response_time' => 100, 'status_code' => 200, 'checked_at' => now()->subDays(3)->setTime(9, 0),
    ]);

    expect(foldRollups($project))->toBeGreaterThan(0)
        ->and(UptimeDailyRollup::count())->toBe(1);
});

test('a backfill that finds no records leaves no stale day behind', function () {
    $project = Project::factory()->create();

    ingestAt($project, now()->subDays(2)->setTime(8, 0)->toDateTimeString(), [
        ['t' => 'request', 'status_code' => 200],
    ]);

    foldRollups($project);

    expect(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(1);

    // The raw records are gone, so rebuilding produces nothing — and the day
    // must stop claiming the request that no longer exists.
    DB::table('records')->delete();

    $this->artisan('laraowl:rollups:backfill')->assertSuccessful();

    expect(RecordRollup::count())->toBe(0)
        ->and(RecordDailyRollup::where('type', 'request')->sum('count'))->toBe(0);
});
