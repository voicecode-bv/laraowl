<?php

use App\Models\Project;
use App\Models\Record;
use App\Models\RecordIpBucket;
use App\Models\Threshold;
use App\Services\IngestService;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;

function ingestLookup(Project $project, array $records): void
{
    app(IngestService::class)->ingest($project, $records);
}

test('the user and ip are lifted into indexed columns at ingest', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'request', 'status_code' => 200, 'user' => '42', 'ip' => '10.0.0.1'],
        ['t' => 'request', 'status_code' => 200, 'user' => 'guest_abc', 'ip' => '10.0.0.2'],
        ['t' => 'request', 'status_code' => 200],
        ['t' => 'exception', 'class' => 'E', 'message' => 'm', 'user' => ['id' => 7, 'name' => 'Ada']],
    ]);

    $records = Record::where('project_id', $project->id)->orderBy('id')->get();

    // Guests get a user_key too: a user's history is every record they produced.
    expect($records[0]->user_key)->toBe('42')
        ->and($records[0]->ip)->toBe('10.0.0.1')
        ->and($records[1]->user_key)->toBe('guest_abc')
        ->and($records[2]->user_key)->toBeNull()
        ->and($records[2]->ip)->toBeNull()
        ->and($records[3]->user_key)->toBe('7');
});

test('a user record is keyed by the identifier at its payload root', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'user', 'id' => 99, 'name' => 'Ada Lovelace', 'username' => 'ada@example.com'],
        ['t' => 'user', 'name' => 'Nobody'],
    ]);

    $records = Record::where('project_id', $project->id)->orderBy('id')->get();

    // Keyed, so the name/email lookup behind the user panels can filter on the
    // index instead of on a JSON expression over every user record.
    expect($records[0]->user_key)->toBe('99')
        ->and($records[1]->user_key)->toBeNull();
});

test('user detail lookups never scan the payload column', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'user', 'id' => 5, 'name' => 'Stale Name', 'username' => 'stale@example.com'],
        ['t' => 'user', 'id' => 5, 'name' => 'Grace Hopper', 'username' => 'grace@example.com'],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => 5],
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $stats = app(RecordService::class)->getDashboardStats($project, '24h');
    $user = $stats['active_users']->first();

    // The newest user record wins, and nothing filters on a JSON expression:
    // the identifiers are matched on the indexed `user_key` column instead.
    expect($user->user_identifier)->toBe('Grace Hopper')
        ->and($user->user_email)->toBe('grace@example.com')
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'json_extract'))->all())->toBe([]);
});

test('user history resolves the md5 hash and reads the indexed column', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'request', '_group' => 'a', 'status_code' => 200, 'user' => '42'],
        ['t' => 'request', '_group' => 'a', 'status_code' => 500, 'user' => '42'],
        ['t' => 'request', '_group' => 'a', 'status_code' => 200, 'user' => '99'],
    ]);

    $history = app(RecordService::class)->getUserHistory($project, md5('42'), '24h');

    expect($history['records']->total())->toBe(2)
        ->and((string) $history['user_id'])->toBe('42')
        ->and((int) $history['stats']->total)->toBe(2);

    // The old query filtered on MD5(JSON_EXTRACT(...)), which no index could serve.
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RecordService::class)->getUserHistory($project, md5('42'), '24h');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect($queries)->not->toContain("JSON_EXTRACT(payload, '$.user')");
});

test('an unknown user hash yields an empty history rather than everything', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [['t' => 'request', 'status_code' => 200, 'user' => '42']]);

    $history = app(RecordService::class)->getUserHistory($project, md5('does-not-exist'), '24h');

    expect($history['records']->total())->toBe(0);
});

test('the raw record list total matches an honest count exactly', function () {
    $project = Project::factory()->create();

    $batch = [];
    for ($i = 0; $i < 7; $i++) {
        $batch[] = ['t' => 'log', 'level' => 'info', 'message' => "line {$i}"];
    }
    ingestLookup($project, $batch);

    $paginator = app(RecordService::class)->getLogRecords($project, null, '24h');

    $rawCount = Record::where('project_id', $project->id)->ofType('log')->forPeriod('24h')->count();

    // The total now comes from the rollups, so it must agree to the record.
    expect($paginator->total())->toBe(7)
        ->and($paginator->total())->toBe($rawCount);
});

test('a searched record list keeps counting for real', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'log', 'level' => 'info', 'message' => 'needle here'],
        ['t' => 'log', 'level' => 'info', 'message' => 'nothing'],
    ]);

    // A search changes what is being counted, so the rollup total cannot be used.
    expect(app(RecordService::class)->getLogRecords($project, 'needle', '24h')->total())->toBe(1);
});

test('the paginator no longer counts raw records', function () {
    $project = Project::factory()->create();
    ingestLookup($project, [['t' => 'log', 'level' => 'info', 'message' => 'x']]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RecordService::class)->getPaginatedRecords($project, 'log', null, '24h');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $countsRecords = $queries->contains(fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, '"records"'));

    expect($countsRecords)->toBeFalse();
});

test('distinct counts come from the group rollups', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'exception', '_group' => 'a', 'class' => 'A', 'message' => 'm'],
        ['t' => 'exception', '_group' => 'a', 'class' => 'A', 'message' => 'm'],
        ['t' => 'exception', '_group' => 'b', 'class' => 'B', 'message' => 'm'],
        ['t' => 'mail', '_group' => 'm1', 'class' => 'Welcome', 'mailer' => 'smtp'],
        ['t' => 'notification', '_group' => 'n1', 'class' => 'Shipped', 'channel' => 'mail'],
        ['t' => 'notification', '_group' => 'n2', 'class' => 'Shipped', 'channel' => 'slack'],
    ]);

    $records = app(RecordService::class);

    expect($records->getExceptionStats($project, '24h')['overview']['unique'])->toBe(2)
        ->and($records->getMailStats($project, '24h')['overview']['unique'])->toBe(1)
        ->and($records->getNotificationStats($project, '24h')['overview']['channels'])->toBe(2);
});

test('the security page counts distinct ips from the hourly ip buckets', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [
        ['t' => 'request', 'status_code' => 200, 'ip' => '1.1.1.1'],
        ['t' => 'request', 'status_code' => 200, 'ip' => '1.1.1.1'],
        ['t' => 'request', 'status_code' => 200, 'ip' => '2.2.2.2'],
    ]);

    $overview = app(RecordService::class)->getSecurityStats($project, '24h')['overview'];

    expect($overview['unique_ips'])->toBe(2)
        ->and($overview['total_scanned'])->toBe(3)
        // Two ips, one hour, and the repeat is counted not duplicated.
        ->and(RecordIpBucket::where('project_id', $project->id)->count())->toBe(2)
        ->and((int) RecordIpBucket::where('ip', '1.1.1.1')->value('count'))->toBe(2);
});

test('a distinct ip seen across two hours is counted once', function () {
    $project = Project::factory()->create();

    $this->travelTo(now()->subHours(3)->startOfHour());
    ingestLookup($project, [['t' => 'request', 'status_code' => 200, 'ip' => '5.5.5.5']]);

    $this->travelTo(now()->addHours(2));
    ingestLookup($project, [['t' => 'request', 'status_code' => 200, 'ip' => '5.5.5.5']]);

    $this->travelBack();

    expect(RecordIpBucket::where('project_id', $project->id)->count())->toBe(2)
        ->and(app(RecordService::class)->getSecurityStats($project, '24h')['overview']['unique_ips'])->toBe(1);
});

test('thresholds are loaded once per batch, not once per record', function () {
    $project = Project::factory()->create();

    Threshold::create([
        'project_id' => $project->id,
        'type' => 'route',
        'key' => '/slow',
        'value' => 1_000_000,
        'is_enabled' => true,
    ]);

    $batch = [];
    for ($i = 0; $i < 25; $i++) {
        $batch[] = ['t' => 'request', 'route_path' => '/slow', 'status_code' => 200, 'duration' => 10];
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    ingestLookup($project, $batch);
    $thresholdQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry) => str_contains($entry['query'], 'thresholds'))
        ->count();
    DB::disableQueryLog();

    expect($thresholdQueries)->toBe(1);
});

test('a threshold added between batches is picked up', function () {
    $project = Project::factory()->create();

    ingestLookup($project, [['t' => 'request', 'route_path' => '/slow', 'status_code' => 200, 'duration' => 10_000]]);

    Threshold::create([
        'project_id' => $project->id,
        'type' => 'route',
        'key' => '/slow',
        'value' => 5, // 5ms
        'is_enabled' => true,
    ]);

    // The per-batch cache must not outlive the ingest call. Duration in µs, so
    // 500ms comfortably exceeds the 5ms threshold.
    ingestLookup($project, [['t' => 'request', 'route_path' => '/slow', 'status_code' => 200, 'duration' => 500_000]]);

    expect($project->issues()->where('title', 'Slow Route: /slow')->exists())->toBeTrue();
});

test('the backfill lifts user_key and ip out of legacy payloads', function () {
    $project = Project::factory()->create();

    Record::create([
        'project_id' => $project->id,
        'type' => 'request',
        'fingerprint' => 'f',
        'payload' => ['status_code' => 200, 'user' => '55', 'ip' => '9.9.9.9'],
        'created_at' => now(),
    ]);

    expect(Record::where('project_id', $project->id)->value('user_key'))->toBeNull();

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    $record = Record::where('project_id', $project->id)->first();

    expect($record->user_key)->toBe('55')
        ->and($record->ip)->toBe('9.9.9.9');
});
