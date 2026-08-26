<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Services\AlertService;
use App\Services\IngestService;
use App\Services\RollupWriter;
use App\Services\SecurityService;

test('ingest notifies the alert service on every exception occurrence', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('notifyNewIssue')
        ->twice()
        ->with(Mockery::on(function (Issue $issue) {
            // The current record is linked before the alert service evaluates it.
            return $issue->records()->count() === (int) $issue->occurrences_count;
        }));
    $alertService->shouldReceive('notifyErrorSpike')->never();

    $ingestService = new IngestService($alertService, app(SecurityService::class), app(RollupWriter::class));

    $exception = ['t' => 'exception', 'class' => 'RuntimeException', 'message' => 'Boom', 'file' => 'a.php', 'line' => 1];

    $ingestService->ingest($project, [$exception]);
    $ingestService->ingest($project, [$exception]);

    expect(Issue::where('project_id', $project->id)->first()->occurrences_count)->toBe(2);
});
