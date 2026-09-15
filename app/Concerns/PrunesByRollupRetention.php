<?php

namespace App\Concerns;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

trait PrunesByRollupRetention
{
    /**
     * The `projects` column holding the retention window for this grain.
     *
     * The fine-grained tables and the daily ones are kept for different
     * lengths of time on purpose: the minute buckets only have to survive
     * long enough to answer the live views, while the daily grain is what a
     * month-long chart reads and can be kept for far longer at a fraction of
     * the rows. A model on the daily grain overrides this.
     */
    protected function rollupRetentionColumn(): string
    {
        return 'rollup_retention_days';
    }

    public function prunableByRollupRetention(): Builder
    {
        $column = $this->rollupRetentionColumn();

        $projects = Project::query()
            ->where($column, '>', 0)
            ->get(['id', $column]);

        if ($projects->isEmpty()) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where(function (Builder $query) use ($projects, $column) {
            foreach ($projects as $project) {
                $query->orWhere(function (Builder $builder) use ($project, $column) {
                    $builder->where('project_id', $project->id)
                        ->where('bucket', '<', now()->subDays($project->{$column}));
                });
            }
        });
    }
}
