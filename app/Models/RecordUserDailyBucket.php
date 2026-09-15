<?php

namespace App\Models;

use App\Concerns\IsDailyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of one user's activity for one record type, so a distinct-user count over a long period reads days rather than hours.
 *
 * @see RecordUserBucket
 */
class RecordUserDailyBucket extends Model
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
