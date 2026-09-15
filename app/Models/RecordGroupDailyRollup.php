<?php

namespace App\Models;

use App\Concerns\IsDailyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of a project's counters for one group (route, exception class, job) of one record type.
 *
 * @see RecordGroupRollup
 */
class RecordGroupDailyRollup extends Model
{
    use IsDailyRollup, MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'bucket' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return $this->prunableByRollupRetention();
    }
}
