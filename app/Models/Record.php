<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Record extends Model
{
    use HasFactory, MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'issue_id',
        'type',
        'payload',
        'fingerprint',
        'user_key',
        'ip',
        'trace_id',
        'message',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * Query Scopes
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeLast24Hours($query)
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    public function scopeForPeriod($query, ?string $period, ?string $from = null, ?string $to = null)
    {
        if ($period === 'custom' && $from && $to) {
            return $query->whereBetween('created_at', [$from, $to]);
        }

        // Floored to the minute so a raw count and the minute-bucketed rollups
        // cover exactly the same window, which lets the rollups supply a
        // paginator's total instead of a COUNT(*) over every record.
        return $query->where('created_at', '>=', static::periodStartsAt($period)->startOfMinute());
    }

    /**
     * Resolve a dashboard period into the instant its window opens.
     *
     * An unrecognised period must never widen the window: returning the query
     * unfiltered turned every such call into a full table scan.
     */
    public static function periodStartsAt(?string $period): CarbonInterface
    {
        return match ($period) {
            '1h' => now()->subHour(),
            '7d' => now()->subDays(7),
            '14d' => now()->subDays(14),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };
    }

    /**
     * The first calendar day a period's window covers.
     *
     * The daily rollups cannot open halfway through a day, so a period that
     * {@see periodStartsAt()} opens at "30 x 24 hours ago" opens here on the
     * first day the charts actually draw: `BuildsRollupQueries::periodKeys()`
     * lays out 30 day labels ending today, so the window is today minus 29.
     * Flooring the raw instant instead would pull in a 31st, partial day that
     * no bar shows but every total would count.
     */
    public static function periodStartsAtDay(?string $period): CarbonInterface
    {
        $days = match ($period) {
            '7d' => 7,
            '14d' => 14,
            '30d' => 30,
            default => 1,
        };

        return now()->startOfDay()->subDays($days - 1);
    }

    public function scopeFailed($query)
    {
        return $query->where(function ($q) {
            $q->where('payload->status', 'failed')
                ->orWhere('payload->status_code', '>=', 400);
        });
    }

    /**
     * Get the prunable model query.
     *
     * Set-based on purpose: a correlated `whereExists` against `projects`
     * lets the database evaluate the retention cutoff per row, so this stays
     * a single query regardless of project count instead of fetching every
     * project into PHP and building one `orWhere` per project.
     */
    public function prunable(): Builder
    {
        return static::query()->whereExists(function (QueryBuilder $query) {
            $query->select(DB::raw(1))
                ->from('projects')
                ->whereColumn('projects.id', 'records.project_id')
                ->where('projects.retention_days', '>', 0)
                ->whereRaw($this->retentionCutoffSql(DB::connection()->getDriverName()));
        });
    }

    /**
     * SQL fragment comparing `records.created_at` against the per-project
     * retention cutoff (`now() - projects.retention_days days`).
     *
     * The interval arithmetic isn't portable across drivers, so it's
     * expressed explicitly per driver: MySQL/MariaDB (production) via
     * `DATE_SUB`; PostgreSQL via multiplying an `INTERVAL` by the
     * `retention_days` column; SQLite (tests) via the `datetime()` modifier
     * built through string concatenation.
     */
    private function retentionCutoffSql(string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'records.created_at < DATE_SUB(NOW(), INTERVAL projects.retention_days DAY)',
            'pgsql' => "records.created_at < NOW() - (INTERVAL '1 day' * projects.retention_days)",
            'sqlite' => "records.created_at < datetime('now', '-' || projects.retention_days || ' days')",
            default => throw new \RuntimeException("Unsupported database driver for retention pruning: {$driver}"),
        };
    }
}
