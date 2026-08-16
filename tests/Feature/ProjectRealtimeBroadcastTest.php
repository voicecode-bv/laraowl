<?php

use App\Enums\TeamRole;
use App\Events\ProjectDataIngested;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\IngestService;
use Illuminate\Support\Facades\Event;

/**
 * A user, the team they own, and a project in it.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function realtimeTenant(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $project];
}

/**
 * Point the broadcaster at Reverb with dummy credentials so channel
 * authorization can sign responses without touching the network.
 *
 * Channels are registered against whichever driver was the default at boot,
 * so they have to be re-registered once the default is swapped.
 */
function useReverbBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    require base_path('routes/channels.php');
}

test('ingesting records broadcasts on the project channel', function () {
    Event::fake([ProjectDataIngested::class]);

    [, , $project] = realtimeTenant();

    app(IngestService::class)->ingest($project, [
        ['t' => 'log', 'message' => 'hello'],
    ]);

    Event::assertDispatched(ProjectDataIngested::class, function (ProjectDataIngested $event) use ($project) {
        expect($event->broadcastOn()[0]->name)->toBe('private-project.'.$project->id);
        expect($event->broadcastAs())->toBe('ProjectDataIngested');
        expect($event->broadcastWith()['project']['id'])->toBe($project->id);

        return true;
    });
});

test('a team member can authorize the private project channel', function () {
    useReverbBroadcaster();

    [$user, , $project] = realtimeTenant();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-project.'.$project->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('a user outside the team cannot authorize the private project channel', function () {
    useReverbBroadcaster();

    [, , $project] = realtimeTenant();
    [$outsider] = realtimeTenant();

    $this->actingAs($outsider)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-project.'.$project->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('a guest cannot authorize the private project channel', function () {
    useReverbBroadcaster();

    [, , $project] = realtimeTenant();

    $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-project.'.$project->id,
        'socket_id' => '1234.5678',
    ])->assertForbidden();
});

test('the root template exposes a csrf token for the echo authorizer', function () {
    [$user, $team, $project] = realtimeTenant();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertSee('name="csrf-token"', false);
});
