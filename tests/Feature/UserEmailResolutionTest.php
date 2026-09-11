<?php

use App\Models\Project;
use App\Services\IngestService;
use App\Services\RecordService;

test('user stats resolve real email from user detail records', function () {
    $project = Project::factory()->create();

    // Ingested, because the users list now ranks over the rollup's user buckets.
    app(IngestService::class)->ingest($project, [
        [
            't' => 'user',
            'id' => 123,
            'name' => 'Ada Lovelace',
            'username' => 'ada@example.com',
        ],
        [
            't' => 'request',
            '_group' => 'request-user-123',
            'user' => 123,
            'status_code' => 200,
            'duration' => 25,
        ],
    ]);

    $stats = app(RecordService::class)->getUserStats($project, '24h');
    $user = $stats['users']->items()[0];

    expect($user->user_name)->toBe('Ada Lovelace')
        ->and($user->user_email)->toBe('ada@example.com')
        ->and((string) $user->user_id)->toBe('123');
});

test('dashboard active users resolve real email from user detail records', function () {
    $project = Project::factory()->create();

    // Ingested rather than inserted, because the top-users panel now ranks over
    // the rollup's user buckets instead of grouping raw JSON.
    app(IngestService::class)->ingest($project, [
        [
            't' => 'user',
            'id' => 456,
            'name' => 'Grace Hopper',
            'username' => 'grace@example.com',
        ],
        [
            't' => 'request',
            '_group' => 'request-user-456',
            'user' => 456,
            'status_code' => 200,
            'duration' => 25,
        ],
    ]);

    $stats = app(RecordService::class)->getDashboardStats($project, '24h');
    $user = $stats['active_users']->first();

    expect($user->user_identifier)->toBe('Grace Hopper')
        ->and($user->user_email)->toBe('grace@example.com')
        ->and((string) $user->user_id)->toBe('456');
});

test('user history resolves real email from user detail records', function () {
    $project = Project::factory()->create();

    $project->records()->create([
        'type' => 'user',
        // Keyed like the ingest path does, because the detail lookup filters
        // on the indexed column rather than on the JSON payload.
        'user_key' => '789',
        'payload' => [
            't' => 'user',
            'id' => 789,
            'name' => 'Katherine Johnson',
            'username' => 'katherine@example.com',
        ],
        'created_at' => now(),
    ]);

    $project->records()->create([
        'type' => 'request',
        'fingerprint' => 'request-user-789',
        'payload' => [
            't' => 'request',
            'user' => 789,
            'status_code' => 200,
            'duration' => 25,
        ],
        'created_at' => now(),
    ]);

    $history = app(RecordService::class)->getUserHistory($project, md5('789'), '24h');

    expect($history['user_name'])->toBe('Katherine Johnson')
        ->and($history['user_email'])->toBe('katherine@example.com')
        ->and((string) $history['user_id'])->toBe('789');
});
