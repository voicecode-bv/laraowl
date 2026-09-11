<?php

use App\Models\Project;
use App\Services\AlertService;
use App\Services\IngestService;
use App\Services\RollupWriter;
use App\Services\SecurityService;
use Illuminate\Support\Facades\DB;

/**
 * The spike check reads the rollups, which already hold one row per minute
 * per type, rather than counting the raw exceptions it is trying to detect.
 */
function spikeIngest(AlertService $alertService): IngestService
{
    return new IngestService($alertService, app(SecurityService::class), app(RollupWriter::class));
}

function exceptions(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $i) => ['t' => 'exception', 'class' => 'E', 'message' => "boom {$i}"])
        ->all();
}

test('a batch that crosses the threshold raises one spike alert', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $alertService = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    // Once for the batch, not once per exception in it, and counting the
    // batch that just arrived.
    $alertService->shouldReceive('notifyErrorSpike')
        ->once()
        ->with(Mockery::type(Project::class), 12, 5);

    spikeIngest($alertService)->ingest($project, exceptions(12));
});

test('a batch below the threshold raises nothing', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $alertService = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $alertService->shouldReceive('notifyErrorSpike')->never();

    spikeIngest($alertService)->ingest($project, exceptions(9));
});

test('the window adds up the batches inside it', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $quiet = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $quiet->shouldReceive('notifyErrorSpike')->never();

    $this->travelTo(now()->subMinutes(3));
    spikeIngest($quiet)->ingest($project, exceptions(6));
    $this->travelBack();

    $alerting = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $alerting->shouldReceive('notifyErrorSpike')->once()->with(Mockery::type(Project::class), 11, 5);

    spikeIngest($alerting)->ingest($project, exceptions(5));
});

test('errors older than the window are left behind', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $quiet = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $quiet->shouldReceive('notifyErrorSpike')->never();

    // Eight then five: thirteen in total, which would cross the threshold if
    // the window did not end before the older batch.
    $this->travelTo(now()->subMinutes(30));
    spikeIngest($quiet)->ingest($project, exceptions(8));
    $this->travelBack();

    spikeIngest($quiet)->ingest($project, exceptions(5));
});

test('a second spike inside the same window stays quiet', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $alertService = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $alertService->shouldReceive('notifyErrorSpike')->once();

    $ingest = spikeIngest($alertService);
    $ingest->ingest($project, exceptions(12));
    $ingest->ingest($project->fresh(), exceptions(12));
});

test('the spike check never counts the raw records', function () {
    $project = Project::factory()->create(['settings' => ['spike_window' => 5, 'spike_threshold' => 10]]);

    $alertService = Mockery::mock(AlertService::class)->shouldIgnoreMissing();
    $ingest = spikeIngest($alertService);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $ingest->ingest($project, exceptions(12));

    $counts = collect($queries)->filter(
        fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'records')
    );

    expect($counts)->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'sum("count")') && str_contains($sql, 'record_rollups')))
        ->toHaveCount(1);
});
