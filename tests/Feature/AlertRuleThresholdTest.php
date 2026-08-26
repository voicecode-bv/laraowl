<?php

use App\Models\AlertRule;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Record;
use App\Models\Team;
use App\Services\AlertService;
use App\Services\IntegrationService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->integration = Integration::create([
        'project_id' => $this->project->id,
        'name' => 'Slack',
        'type' => 'slack',
        'data' => ['webhook_url' => 'https://hooks.slack.test/abc'],
        'is_enabled' => true,
    ]);

    $this->integrationService = Mockery::mock(IntegrationService::class);
    $this->alertService = new AlertService($this->integrationService);
});

function createRule(Project $project, Integration $integration, array $settings, string $eventType = 'new_exception'): AlertRule
{
    $rule = $project->alertRules()->create([
        'name' => 'Rule',
        'event_type' => $eventType,
        'settings' => $settings,
        'is_enabled' => true,
    ]);
    $rule->integrations()->attach($integration);

    return $rule;
}

function createIssue(Project $project, int $occurrences = 1, bool $recentlyCreated = false): Issue
{
    $issue = $project->issues()->create([
        'hash' => md5('issue-'.$occurrences),
        'title' => 'RuntimeException',
        'message' => 'Something broke',
        'status' => 'open',
        'occurrences_count' => $occurrences,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $issue->wasRecentlyCreated = $recentlyCreated;

    return $issue;
}

test('a rule with threshold 1 fires only when the issue is new', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 1, 'throttle_period' => 0]);

    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue(createIssue($this->project, 1, true));

    $this->integrationService->shouldNotReceive('send');
    $this->alertService->notifyNewIssue(createIssue($this->project, 2, false));
});

test('a rule without threshold settings behaves like threshold 1', function () {
    createRule($this->project, $this->integration, ['frequency' => 'immediate', 'throttle_period' => 0]);

    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue(createIssue($this->project, 1, true));
});

test('a rule with a threshold and no window fires once when lifetime occurrences reach it', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 3, 'time_window' => 0, 'throttle_period' => 0]);

    $issue = createIssue($this->project, 1, true);

    $this->integrationService->shouldNotReceive('send');
    $this->alertService->notifyNewIssue($issue);

    $issue->occurrences_count = 2;
    $issue->wasRecentlyCreated = false;
    $this->alertService->notifyNewIssue($issue);

    Mockery::close();
    $this->integrationService = Mockery::mock(IntegrationService::class);
    $this->alertService = new AlertService($this->integrationService);

    $this->integrationService->shouldReceive('send')->once();
    $issue->occurrences_count = 3;
    $this->alertService->notifyNewIssue($issue);

    $issue->occurrences_count = 4;
    $this->alertService->notifyNewIssue($issue);
});

test('a rule with a time window only counts occurrences inside the window', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 3, 'time_window' => 10, 'throttle_period' => 0]);

    $issue = createIssue($this->project, 5);

    // Three old occurrences outside the window, two inside.
    Record::factory()->count(3)->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now()->subMinutes(30),
    ]);
    Record::factory()->count(2)->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now()->subMinutes(2),
    ]);

    $this->integrationService->shouldNotReceive('send');
    $this->alertService->notifyNewIssue($issue);

    Mockery::close();
    $this->integrationService = Mockery::mock(IntegrationService::class);
    $this->alertService = new AlertService($this->integrationService);

    Record::factory()->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now(),
    ]);

    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue($issue);

    // Further occurrences inside the same window do not re-alert.
    Record::factory()->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now(),
    ]);
    $this->alertService->notifyNewIssue($issue);
});

test('a windowed rule can fire again once the window has elapsed', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 2, 'time_window' => 10, 'throttle_period' => 0]);

    $issue = createIssue($this->project, 4);

    Record::factory()->count(2)->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now(),
    ]);

    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue($issue);

    $this->travel(11)->minutes();

    Record::factory()->count(2)->create([
        'project_id' => $this->project->id,
        'issue_id' => $issue->id,
        'type' => 'exception',
        'created_at' => now(),
    ]);

    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue($issue);
});

test('rules are evaluated independently for the same issue', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 1, 'throttle_period' => 0]);
    createRule($this->project, $this->integration, ['occurrence_threshold' => 5, 'throttle_period' => 0]);

    // New issue: only the threshold-1 rule fires.
    $this->integrationService->shouldReceive('send')->once();
    $this->alertService->notifyNewIssue(createIssue($this->project, 1, true));
});

test('high latency rules honour the occurrence threshold too', function () {
    createRule($this->project, $this->integration, ['occurrence_threshold' => 2, 'throttle_period' => 0], 'high_latency');

    $issue = createIssue($this->project, 1, true);

    $this->integrationService->shouldNotReceive('send');
    $this->alertService->notifySlowPerformance($issue);

    Mockery::close();
    $this->integrationService = Mockery::mock(IntegrationService::class);
    $this->alertService = new AlertService($this->integrationService);

    $this->integrationService->shouldReceive('send')->once();
    $issue->occurrences_count = 2;
    $issue->wasRecentlyCreated = false;
    $this->alertService->notifySlowPerformance($issue);
});

test('disabled rules and disabled integrations are skipped', function () {
    $rule = createRule($this->project, $this->integration, ['occurrence_threshold' => 1, 'throttle_period' => 0]);
    $rule->update(['is_enabled' => false]);

    $this->integrationService->shouldNotReceive('send');
    $this->alertService->notifyNewIssue(createIssue($this->project, 1, true));

    $rule->update(['is_enabled' => true]);
    $this->integration->update(['is_enabled' => false]);
    $this->alertService->notifyNewIssue(createIssue($this->project, 2, true));
});
