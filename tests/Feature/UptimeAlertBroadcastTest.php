<?php

use App\Enums\TeamRole;
use App\Events\ProjectUptimeChanged;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\AlertService;
use App\Services\RecordService;
use App\Support\TeamProjectScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The dashboard's realtime outage alert: what is broadcast, when, and who is
 * allowed to hear it.
 */
function monitoredProject(array $attributes = []): Project
{
    return Project::factory()->create([
        'url' => 'https://example.com',
        'uptime_monitoring_enabled' => true,
        ...$attributes,
    ]);
}

function silenceAlerts(): void
{
    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('notifyUptimeDown')->zeroOrMoreTimes();
    $alertService->shouldReceive('notifyHeartbeatFailed')->zeroOrMoreTimes();
    test()->instance(AlertService::class, $alertService);
}

test('going down broadcasts on the team channel', function () {
    Event::fake([ProjectUptimeChanged::class]);
    silenceAlerts();

    $project = monitoredProject(['last_uptime_status' => 'up']);

    Http::fake(['*' => Http::response('boom', 503)]);

    $this->artisan('projects:check-health')->assertSuccessful();

    Event::assertDispatched(
        ProjectUptimeChanged::class,
        function (ProjectUptimeChanged $event) use ($project) {
            $channels = collect($event->broadcastOn())->map->name;

            return $event->project->is($project)
                && $event->status === 'down'
                && $channels->contains('private-team.'.$project->team_id);
        },
    );
});

test('recovering broadcasts too, so the banner can clear itself', function () {
    Event::fake([ProjectUptimeChanged::class]);
    silenceAlerts();

    monitoredProject(['last_uptime_status' => 'down']);

    Http::fake(['*' => Http::response('OK', 200)]);

    $this->artisan('projects:check-health')->assertSuccessful();

    Event::assertDispatched(
        ProjectUptimeChanged::class,
        fn (ProjectUptimeChanged $event) => $event->status === 'up',
    );
});

test('an application that stays down does not broadcast again', function () {
    Event::fake([ProjectUptimeChanged::class]);
    silenceAlerts();

    monitoredProject(['last_uptime_status' => 'down']);

    Http::fake(['*' => Http::response('boom', 503)]);

    $this->artisan('projects:check-health')->assertSuccessful();

    Event::assertNotDispatched(ProjectUptimeChanged::class);
});

test('the payload names the application and trims the error to one line', function () {
    $project = monitoredProject();

    $event = new ProjectUptimeChanged($project, 'down', 503, str_repeat('x', 400));
    $payload = $event->broadcastWith();

    expect($payload['project']['name'])->toBe($project->name)
        ->and($payload['project']['slug'])->toBe($project->slug)
        ->and($payload['status'])->toBe('down')
        ->and($payload['status_code'])->toBe(503)
        ->and(mb_strlen($payload['error']))->toBe(160)
        ->and($payload['changed_at'])->not->toBeNull();
});

test('only a member of the team may listen on its channel', function () {
    // The suite runs on the `null` broadcaster, which authorises every
    // channel without consulting the callback — so this names a real driver
    // to reach the authorisation being asserted.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    require base_path('routes/channels.php');

    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Owner->value]);
    $outsider = User::factory()->create();

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-team.'.$team->id, 'socket_id' => '1.1'])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($outsider)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-team.'.$team->id, 'socket_id' => '1.1'])
        ->assertForbidden();
});

test('the dashboard arrives knowing which applications are already down', function () {
    $team = Team::factory()->create();

    $down = monitoredProject(['team_id' => $team->id, 'name' => 'Checkout', 'last_uptime_status' => 'down']);
    monitoredProject(['team_id' => $team->id, 'name' => 'Shop', 'last_uptime_status' => 'up']);

    $status = app(RecordService::class)
        ->getDashboardSummary(new TeamProjectScope($team), '1h')['uptime_status'];

    expect($status['offline'])->toHaveCount(1)
        ->and($status['offline'][0]['name'])->toBe('Checkout')
        ->and($status['offline'][0]['slug'])->toBe($down->slug)
        ->and($status['down'])->toBe(1);
});

test('a healthy scope reports nothing offline', function () {
    $team = Team::factory()->create();
    monitoredProject(['team_id' => $team->id, 'last_uptime_status' => 'up']);

    $status = app(RecordService::class)
        ->getDashboardSummary(new TeamProjectScope($team), '1h')['uptime_status'];

    expect($status['offline'])->toBe([]);
});
