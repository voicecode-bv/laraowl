<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Record;
use App\Services\RollupCompactor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour of the daily grain of the rollup family.
 *
 * Each of these models mirrors a finer-grained sibling column for column, so
 * that a read over a long period can swap the table it queries and change
 * nothing else about the query. What differs is the window: a day bucket is
 * addressed by the calendar day it covers, and it is kept for its own,
 * longer retention.
 *
 * @see RollupCompactor
 */
trait IsDailyRollup
{
    use PrunesByRollupRetention;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function rollupRetentionColumn(): string
    {
        return 'daily_rollup_retention_days';
    }

    /**
     * Limit the query to the calendar days covered by the requested period.
     */
    public function scopeForPeriod(Builder $query, ?string $period, ?string $from = null, ?string $to = null): Builder
    {
        if ($period === 'custom' && $from && $to) {
            return $query->whereBetween('bucket', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]);
        }

        return $query->where('bucket', '>=', Record::periodStartsAtDay($period));
    }
}
