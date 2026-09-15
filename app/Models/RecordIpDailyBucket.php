<?php

namespace App\Models;

use App\Concerns\IsDailyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of one IP's requests, so a distinct-IP count over a long period reads days rather than hours.
 *
 * @see RecordIpBucket
 */
class RecordIpDailyBucket extends Model
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
