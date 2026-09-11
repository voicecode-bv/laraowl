<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Record;
use App\Models\RecordGroupRollup;
use App\Models\RecordGroupUserBucket;
use App\Models\RecordIpBucket;
use App\Models\RecordRollup;
use App\Models\RecordUserBucket;
use App\Services\RollupWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillRollups extends Command
{
    protected $signature = 'laraowl:rollups:backfill
                            {--project= : Restrict the rebuild to one project id or slug}
                            {--missing : Only rebuild projects that have records but no rollups yet}
                            {--since= : Only rebuild buckets at or after this datetime}
                            {--until= : Only rebuild buckets before this datetime}
                            {--chunk=2000 : Raw records read per batch}';

    protected $description = 'Rebuild the dashboard rollups from raw records';

    protected const UPDATE_BATCH = 500;

    public function handle(RollupWriter $rollupWriter): int
    {
        $projects = $this->targetProjects();

        if ($projects->isEmpty()) {
            if ($this->option('missing')) {
                $this->info('Every project already has rollups.');

                return self::SUCCESS;
            }

            $this->error('No matching projects.');

            return self::FAILURE;
        }

        $since = $this->option('since');
        $until = $this->option('until');
        $chunkSize = max(1, (int) $this->option('chunk'));

        foreach ($projects as $project) {
            $this->rebuild($project, $rollupWriter, $since, $until, $chunkSize);
        }

        $this->newLine();
        $this->info('Rollups rebuilt.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Project>
     */
    protected function targetProjects(): Collection
    {
        $identifier = $this->option('project');

        if ($identifier) {
            return Project::query()
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->get();
        }

        if ($this->option('missing')) {
            return Project::query()
                ->whereHas('records')
                ->whereDoesntHave('rollups')
                ->get();
        }

        return Project::all();
    }

    protected function rebuild(Project $project, RollupWriter $rollupWriter, ?string $since, ?string $until, int $chunkSize): void
    {
        $this->newLine();
        $this->info("Rebuilding {$project->slug}");

        $this->discardExistingRollups($project, $since, $until);

        $total = $this->recordsQuery($project, $since, $until)->count();

        if ($total === 0) {
            $this->line('  no records in range');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $this->recordsQuery($project, $since, $until)
            ->chunkById($chunkSize, function (Collection $chunk) use ($project, $rollupWriter, $bar) {
                $batch = $chunk->map(fn (Record $record) => [
                    'type' => $record->type,
                    'payload' => $record->payload ?? [],
                    'fingerprint' => $record->fingerprint,
                    'created_at' => $record->created_at,
                ])->all();

                $rollupWriter->record($project, $batch);
                $this->backfillRecordColumns($chunk, $rollupWriter);

                $bar->advance($chunk->count());
            });

        $bar->finish();
        $this->newLine();
    }

    /**
     * @param  Collection<int, Record>  $chunk
     */
    protected function backfillRecordColumns(Collection $chunk, RollupWriter $rollupWriter): void
    {
        $updates = $chunk
            ->map(fn (Record $record) => [
                'id' => $record->id,
                'user_key' => $rollupWriter->rawUserKeyFor($record->type, $record->payload ?? []),
                'ip' => $rollupWriter->ipFor($record->payload ?? []),
                'trace_id' => $rollupWriter->traceIdFor($record->payload ?? []),
                'message' => $rollupWriter->messageFor($record->type, $record->payload ?? []),
            ])
            ->filter(fn (array $row) => $row['user_key'] !== null || $row['ip'] !== null || $row['trace_id'] !== null || $row['message'] !== null);

        foreach ($updates->chunk(static::UPDATE_BATCH) as $batch) {
            $this->updateRecordColumns($batch->values());
        }
    }

    /**
     * @param  Collection<int, array{id: int, user_key: string|null, ip: string|null, trace_id: string|null, message: string|null}>  $rows
     */
    protected function updateRecordColumns(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $bindings = [];
        $cases = [];

        foreach (['user_key', 'ip', 'trace_id', 'message'] as $column) {
            $when = '';
            foreach ($rows as $row) {
                $when .= ' when ? then ?';
                $bindings[] = $row['id'];
                $bindings[] = $row[$column];
            }
            $cases[] = $grammar->wrap($column).' = case '.$grammar->wrap('id').$when.' end';
        }

        $ids = $rows->pluck('id')->all();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        DB::update(
            'update '.$grammar->wrap('records').' set '.implode(', ', $cases)
                .' where '.$grammar->wrap('id')." in ({$placeholders})",
            array_merge($bindings, $ids),
        );
    }

    /**
     * Clear the range being rebuilt, so a re-run cannot accumulate.
     */
    protected function discardExistingRollups(Project $project, ?string $since, ?string $until): void
    {
        $tables = [
            RecordRollup::query(),
            RecordGroupRollup::query(),
            RecordUserBucket::query(),
            RecordGroupUserBucket::query(),
            RecordIpBucket::query(),
        ];

        foreach ($tables as $query) {
            $query->where('project_id', $project->id)
                ->when($since, fn (Builder $builder) => $builder->where('bucket', '>=', $since))
                ->when($until, fn (Builder $builder) => $builder->where('bucket', '<', $until))
                ->delete();
        }
    }

    /**
     * @return Builder<Record>
     */
    protected function recordsQuery(Project $project, ?string $since, ?string $until): Builder
    {
        return Record::query()
            ->where('project_id', $project->id)
            ->when($since, fn (Builder $builder) => $builder->where('created_at', '>=', $since))
            ->when($until, fn (Builder $builder) => $builder->where('created_at', '<', $until));
    }
}
