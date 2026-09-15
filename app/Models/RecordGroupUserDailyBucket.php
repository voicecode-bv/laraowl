<?php

namespace App\Models;

use App\Concerns\IsDailyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One user seen on one group on one day, so 'how many users hit this exception' reads days rather than hours.
 *
 * @see RecordGroupUserBucket
 */
class RecordGroupUserDailyBucket extends Model
{
    use IsDailyRollup, MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'bucket' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return $this->prunableByRollupRetention();
    }
}
