<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\RollupCompactor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CompactRollups extends Command
{
    protected $signature = 'laraowl:rollups:compact
                            {--project= : Restrict the pass to one project id or slug}
                            {--since= : Rebuild from this day instead of resuming at the watermark}
                            {--until= : Rebuild up to and including this day instead of today}';

    protected $description = 'Fold the per-minute rollups into the daily grain the 7/14/30 day views read';

    public function handle(RollupCompactor $compactor): int
    {
        $projects = $this->targetProjects();

        if ($projects->isEmpty()) {
            $this->error('No matching projects.');

            return self::FAILURE;
        }

        $since = $this->option('since');
        $until = $this->option('until');
        $days = 0;

        foreach ($projects as $project) {
            $folded = $compactor->compact(
                $project,
                $since ? CarbonImmutable::parse($since) : null,
                $until ? CarbonImmutable::parse($until) : null,
            );

            $days += $folded;

            if ($this->output->isVerbose()) {
                $this->line("  {$project->slug}: {$folded} ".str('day')->plural($folded));
            }
        }

        $this->info("Compacted {$days} ".str('day')->plural($days).' across '.$projects->count().' '.str('project')->plural($projects->count()).'.');

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

        return Project::all();
    }
}
