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
     */
    public function scopeForPeriod(Builder $query, ?string $period, ?string $from = null, ?string $to = null): Builder
    {
        if ($period === null || $period === 'all') {
            return $query;
        }

        if ($period === 'custom' && $from && $to) {
            return $query->whereBetween('checked_at', [$from, $to]);
        }

        return $query->where('checked_at', '>=', Record::periodStartsAt($period));
    }
}
