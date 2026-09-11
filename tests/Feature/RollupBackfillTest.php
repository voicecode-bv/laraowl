<?php

use App\Models\Project;
use App\Models\Record;
use App\Models\RecordRollup;
use App\Models\RecordUserBucket;
use App\Services\IngestService;
use Illuminate\Support\Facades\DB;

function rollupSnapshot(Project $project): array
{
    return RecordRollup::where('project_id', $project->id)
        ->orderBy('type')
        ->orderBy('bucket')
        ->get()
        ->map(fn (RecordRollup $rollup) => collect($rollup->getAttributes())->except('id')->all())
        ->all();
}

test('a backfill rebuilds exactly what live ingestion would have written', function () {
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => '1'],
        ['t' => 'request', 'status_code' => 500, 'duration' => 3000, 'user' => '2'],
        ['t' => 'exception', 'class' => 'E', 'message' => 'm', 'user' => '1'],
        ['t' => 'cache-event', 'key' => 'k', 'type' => 'hit'],
    ]);

    $live = rollupSnapshot($project);
    $liveUsers = RecordUserBucket::where('project_id', $project->id)->count();

    // Throw the rollups away, leaving only the raw records behind.
    RecordRollup::where('project_id', $project->id)->delete();
    RecordUserBucket::where('project_id', $project->id)->delete();

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    expect(rollupSnapshot($project))->toEqual($live)
        ->and(RecordUserBucket::where('project_id', $project->id)->count())->toBe($liveUsers);
});

test('running the backfill twice does not double count', function () {
    $project = Project::factory()->create();

    app(IngestService::class)->ingest($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => '1'],
        ['t' => 'request', 'status_code' => 200, 'duration' => 10, 'user' => '1'],
    ]);

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);
    $once = rollupSnapshot($project);

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    // The live path increments, so a replay that did not clear first would
    // silently accumulate on every run.
    expect(rollupSnapshot($project))->toEqual($once);

    $rollup = RecordRollup::where('project_id', $project->id)->first();
    expect($rollup->count)->toBe(2);
});

test('the backfill can be limited to one project', function () {
    $mine = Project::factory()->create();
    $theirs = Project::factory()->create();

    foreach ([$mine, $theirs] as $project) {
        app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200]]);
    }

    RecordRollup::query()->delete();

    $this->artisan('laraowl:rollups:backfill', ['--project' => $mine->slug])->assertExitCode(0);

    expect(RecordRollup::where('project_id', $mine->id)->exists())->toBeTrue()
        ->and(RecordRollup::where('project_id', $theirs->id)->exists())->toBeFalse();
});

test('--missing rebuilds only projects whose rollups are empty', function () {
    $upgraded = Project::factory()->create();
    $healthy = Project::factory()->create();

    foreach ([$upgraded, $healthy] as $project) {
        app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200]]);
    }

    // Simulate the upgrade: raw records survive, the new tables start empty.
    RecordRollup::where('project_id', $upgraded->id)->delete();

    $this->artisan('laraowl:rollups:backfill', ['--missing' => true])->assertExitCode(0);

    expect(RecordRollup::where('project_id', $upgraded->id)->exists())->toBeTrue();

    // The healthy project was not touched, so its counters cannot have doubled.
    expect((int) RecordRollup::where('project_id', $healthy->id)->first()->count)->toBe(1);
});

test('--missing is a no-op once every project has rollups', function () {
    $project = Project::factory()->create();
    app(IngestService::class)->ingest($project, [['t' => 'request', 'status_code' => 200]]);

    $this->artisan('laraowl:rollups:backfill', ['--missing' => true])
        ->expectsOutputToContain('Every project already has rollups')
        ->assertExitCode(0);

    expect((int) RecordRollup::where('project_id', $project->id)->first()->count)->toBe(1);
});

test('a project with no records in range is skipped cleanly', function () {
    Project::factory()->create();

    $this->artisan('laraowl:rollups:backfill')
        ->expectsOutputToContain('no records in range')
        ->assertExitCode(0);

    expect(RecordRollup::count())->toBe(0);
});

test('the backfill buckets a record by the minute it was created', function () {
    $project = Project::factory()->create();

    $createdAt = now()->subMinutes(30)->startOfMinute()->addSeconds(37);

    Record::create([
        'project_id' => $project->id,
        'type' => 'request',
        'fingerprint' => 'f',
        'payload' => ['status_code' => 200, 'duration' => 5],
        'created_at' => $createdAt,
    ]);

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    $rollup = RecordRollup::where('project_id', $project->id)->first();

    expect($rollup->bucket->format('Y-m-d H:i:s'))
        ->toBe($createdAt->copy()->startOfMinute()->format('Y-m-d H:i:s'));
});

test('the command keys existing user records by their payload id', function () {
    $project = Project::factory()->create();

    Record::create([
        'project_id' => $project->id,
        'type' => 'user',
        'payload' => ['t' => 'user', 'id' => 321, 'name' => 'Ada Lovelace'],
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('laraowl:rollups:backfill')->assertExitCode(0);

    expect(Record::where('project_id', $project->id)->value('user_key'))->toBe('321');
});

test('the migration keys user records that were ingested before they were keyed', function () {
    $project = Project::factory()->create();

    $keyed = DB::table('records')->insertGetId([
        'project_id' => $project->id,
        'type' => 'user',
        'payload' => json_encode(['t' => 'user', 'id' => 654, 'name' => 'Grace Hopper']),
        'created_at' => now(),
    ]);

    $anonymous = DB::table('records')->insertGetId([
        'project_id' => $project->id,
        'type' => 'user',
        'payload' => json_encode(['t' => 'user', 'name' => 'Nobody']),
        'created_at' => now(),
    ]);

    $request = DB::table('records')->insertGetId([
        'project_id' => $project->id,
        'type' => 'request',
        'payload' => json_encode(['t' => 'request', 'status_code' => 200]),
        'created_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_09_11_072622_backfill_user_key_on_user_records.php');
    $migration->up();

    expect(DB::table('records')->where('id', $keyed)->value('user_key'))->toBe('654')
        ->and(DB::table('records')->where('id', $anonymous)->value('user_key'))->toBeNull()
        // Only user records carry their identifier at the payload root.
        ->and(DB::table('records')->where('id', $request)->value('user_key'))->toBeNull();
});
