<?php

namespace App\Models;

use App\Concerns\IsDailyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of a project's uptime checks.
 *
 * @see UptimeCheck
 */
class UptimeDailyRollup extends Model
{
    use IsDailyRollup, MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'bucket' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return $this->prunableByRollupRetention();
    }
}
