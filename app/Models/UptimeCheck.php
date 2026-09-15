<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'status',
        'response_time',
        'status_code',
        'error',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Limit the query to the checks inside the requested period.
     *
     * Shares its period vocabulary with the telemetry screens
     * ({@see Record::periodStartsAt()}) so "24h" means the same thing on
     * every card, and `all` deliberately spans the whole retained history.
     *
     * The multi-day periods open on a calendar day rather than that many
     * times 24 hours back. The availability chart has always drawn them as
     * day-wide bars, so a rolling window put a 31st, partial day into the
     * summary beside it that no bar accounted for — and it is the day
     * boundary that {@see UptimeDailyRollup} folds to, so aligning here is
     * what lets the folded days answer for the raw checks exactly.
     */
    public function scopeForPeriod(Builder $query, ?string $period, ?string $from = null, ?string $to = null): Builder
    {
        if ($period === null || $period === 'all') {
            return $query;
        }

        if ($period === 'custom' && $from && $to) {
            return $query->whereBetween('checked_at', [$from, $to]);
        }

        $opensAt = in_array($period, ['7d', '14d', '30d'], true)
            ? Record::periodStartsAtDay($period)
            : Record::periodStartsAt($period);

        return $query->where('checked_at', '>=', $opensAt);
    }
}
