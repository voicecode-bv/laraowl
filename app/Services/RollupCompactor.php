<?php

namespace App\Services;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Folds the fine-grained rollups into the daily grain that the long periods
 * read.
 *
 * {@see RollupWriter} writes a bucket per minute (and per hour for the user,
 * group and IP tables), which is the right resolution for the live views and
 * far too fine for the rest: a 30-day chart draws 30 bars but used to fold
 * 43,200 minute buckets per record type to get them, on every page view, for
 * every viewer. This runs that fold once, in the background, into tables that
 * mirror their source column for column, so the read side only changes which
 * table it queries.
 *
 * Every pass is idempotent. A day is rebuilt from its source rows and
 * overwritten rather than added to, so re-running the command, overlapping
 * two scheduled passes, or replaying a day after late records arrive all
 * converge on the same numbers.
 */
class RollupCompactor
{
    /**
     * How many already-compacted days each pass rebuilds anyway.
     *
     * The watermark is the first line of defence against records that arrive
     * late enough to land in a day that was already folded — {@see
     * RollupWriter::record()} winds it back when it writes behind it. This is
     * the second: a fixed overlap costs a handful of grouped reads per pass
     * and covers a wind-back that never happened, because the ingest that
     * would have done it failed or ran on an older release.
     */
    public const OVERLAP_DAYS = 2;

    /**
     * The tables a pass folds.
     *
     * `column` is the source's own timestamp — the rollup family buckets it
     * as `bucket`, while `uptime_checks` is raw rows stamped `checked_at`,
     * which folds the same way. `keys` are the columns a day is grouped by
     * on top of the project; uptime has none, so a day of it is one row.
     *
     * @return list<array{source: string, column: string, destination: string, keys: list<string>}>
     */
    public static function tables(): array
    {
        return [
            ['source' => 'record_rollups', 'column' => 'bucket', 'destination' => 'record_daily_rollups', 'keys' => ['type']],
            ['source' => 'record_group_rollups', 'column' => 'bucket', 'destination' => 'record_group_daily_rollups', 'keys' => ['type', 'group_key']],
            ['source' => 'record_user_buckets', 'column' => 'bucket', 'destination' => 'record_user_daily_buckets', 'keys' => ['type', 'user_key']],
            ['source' => 'record_group_user_buckets', 'column' => 'bucket', 'destination' => 'record_group_user_daily_buckets', 'keys' => ['type', 'group_key', 'user_key']],
            ['source' => 'record_ip_buckets', 'column' => 'bucket', 'destination' => 'record_ip_daily_buckets', 'keys' => ['type', 'ip']],
            ['source' => 'uptime_checks', 'column' => 'checked_at', 'destination' => 'uptime_daily_rollups', 'keys' => []],
        ];
    }

    /**
     * Fold every day a project still owes into the daily grain.
     *
     * Returns the number of days folded, which is what the command reports.
     */
    public function compact(Project $project, ?CarbonInterface $since = null, ?CarbonInterface $until = null): int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $last = $until ? CarbonImmutable::parse($until)->startOfDay() : $today;
        $observed = $project->rollups_compacted_through;
        $first = $since
            ? CarbonImmutable::parse($since)->startOfDay()
            : $this->resumePoint($project);

        if ($first === null || $first->greaterThan($last)) {
            // A project with nothing to fold is still folded up to date, and
            // has to say so: the read side treats a project the compactor has
            // never marked as one whose daily grain cannot be trusted yet,
            // and an empty project would otherwise hold a whole team's
            // aggregate on the slow path forever.
            $this->advanceWatermark($project, $observed, $last, $today);

            return 0;
        }

        $days = 0;

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $this->compactDay($project, $day);
            $days++;
        }

        $this->advanceWatermark($project, $observed, $last, $today);

        return $days;
    }

    /**
     * The first day a pass has to rebuild.
     *
     * A project that has been compacted before resumes a couple of days
     * behind its watermark; one that never has starts at its oldest surviving
     * source bucket, and a project with no rollups at all has nothing to do.
     */
    private function resumePoint(Project $project): ?CarbonImmutable
    {
        if ($project->rollups_compacted_through !== null) {
            return CarbonImmutable::parse($project->rollups_compacted_through)
                ->startOfDay()
                ->subDays(static::OVERLAP_DAYS);
        }

        $oldest = collect([
            DB::table('record_rollups')->where('project_id', $project->id)->min('bucket'),
            DB::table('uptime_checks')->where('project_id', $project->id)->min('checked_at'),
        ])->filter()->min();

        return $oldest === null ? null : CarbonImmutable::parse($oldest)->startOfDay();
    }

    /**
     * Record how far the project is folded, in whole days.
     *
     * The current day is compacted too — a 30-day chart would otherwise be
     * missing its last bar — but it is not marked as done, so the next pass
     * rebuilds it with the records that arrived in between.
     *
     * The write is a compare-and-swap against the watermark this pass began
     * with, because ingest can wind it back mid-pass ({@see
     * RollupWriter::record()}). Losing the swap means a late record landed in
     * a day this pass may have folded before that record existed, so the
     * wound-back value is the one to keep: the next pass redoes the day.
     */
    private function advanceWatermark(Project $project, ?CarbonInterface $observed, CarbonImmutable $last, CarbonImmutable $today): void
    {
        $complete = $last->greaterThanOrEqualTo($today) ? $today->subDay() : $last;

        if ($observed !== null && CarbonImmutable::parse($observed)->greaterThanOrEqualTo($complete)) {
            return;
        }

        $swapped = Project::query()
            ->whereKey($project->id)
            ->when(
                $observed === null,
                fn ($query) => $query->whereNull('rollups_compacted_through'),
                fn ($query) => $query->where('rollups_compacted_through', $observed),
            )
            ->update(['rollups_compacted_through' => $complete]);

        if ($swapped > 0) {
            $project->setAttribute('rollups_compacted_through', $complete)->syncOriginalAttribute('rollups_compacted_through');
        }
    }

    /**
     * Rebuild one calendar day of every table for one project.
     *
     * A day whose source rows have been pruned away folds to nothing, and
     * nothing is what gets written: the day already in the daily grain is
     * left alone rather than emptied. That asymmetry is the point of the two
     * retentions — the minute buckets are meant to age out from under a daily
     * row that outlives them by months.
     */
    public function compactDay(Project $project, CarbonInterface $day): void
    {
        $start = CarbonImmutable::parse($day)->startOfDay();
        $end = $start->addDay();

        foreach (static::tables() as $table) {
            $rows = $this->foldedRows($table, $project->id, $start, $end);

            $this->replace($table['destination'], $rows, array_merge(['project_id', 'bucket'], $table['keys']));
        }
    }

    /**
     * One day of a source table, grouped down to a row per key.
     *
     * The day is selected as a half-open range on the source's timestamp
     * rather than by grouping on a date expression: the range is what the
     * table's index covers, and it keeps the SQL free of the three drivers'
     * date functions.
     *
     * @param  array{source: string, column: string, destination: string, keys: list<string>}  $table
     * @return list<array<string, mixed>>
     */
    private function foldedRows(array $table, int $projectId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = $table['keys'];

        $query = DB::table($table['source'])
            ->where('project_id', $projectId)
            ->where($table['column'], '>=', $start)
            ->where($table['column'], '<', $end);

        if ($keys !== []) {
            $query->groupBy($keys);
        }

        $this->selectFolded($query, $table['source'], $keys);

        $bucket = $start->format('Y-m-d H:i:s');

        return $query->get()
            ->map(function (object $row) use ($projectId, $bucket): array {
                $values = ['project_id' => $projectId, 'bucket' => $bucket];

                foreach ((array) $row as $column => $value) {
                    $values[$column] = $value;
                }

                return $values;
            })
            // An ungrouped fold always returns its one row, even for a day
            // the project was not being checked on; that row is all zeroes
            // and NULLs, and writing it would draw a day of total downtime.
            ->reject(fn (array $row): bool => $keys === [] && (int) ($row['checks'] ?? 1) === 0)
            ->values()
            ->all();
    }

    /**
     * The aggregate a source table folds to.
     *
     * Every counter here is additive, so a day is the sum of its buckets.
     * Averages are not, which is why `sum_duration` and `count_duration` are
     * carried separately and divided at read time; the latency histogram is
     * additive per boundary, so percentiles survive the fold as well.
     *
     * @param  list<string>  $keys
     */
    private function selectFolded(Builder $query, string $source, array $keys): void
    {
        $query->select($keys);

        if ($source === 'uptime_checks') {
            $status = $this->wrap('status');
            $responseTime = $this->wrap('response_time');

            $query->addSelect([
                DB::raw('COUNT(*) as '.$this->wrap('checks')),
                DB::raw("SUM(CASE WHEN {$status} = 'up' THEN 1 ELSE 0 END) as ".$this->wrap('up_checks')),
                DB::raw('COALESCE(SUM('.$responseTime.'), 0) as '.$this->wrap('sum_response_time')),
                DB::raw('COUNT('.$responseTime.') as '.$this->wrap('response_count')),
                DB::raw('MAX('.$responseTime.') as '.$this->wrap('max_response_time')),
                DB::raw('MIN('.$responseTime.') as '.$this->wrap('min_response_time')),
                DB::raw('MAX('.$this->wrap('checked_at').') as '.$this->wrap('last_checked_at')),
            ]);

            return;
        }

        if ($source === 'record_group_user_buckets') {
            $query->distinct();

            return;
        }

        if ($source === 'record_ip_buckets') {
            $query->addSelect($this->sum('count'));

            return;
        }

        if ($source === 'record_user_buckets') {
            $query->addSelect([$this->sum('count'), $this->sum('error_count'), $this->max('last_seen_at')]);

            return;
        }

        foreach (RollupWriter::additiveColumns() as $column) {
            $query->addSelect($this->sum($column));
        }

        $query->addSelect([$this->max('max_duration'), $this->min('min_duration')]);

        if ($source === 'record_group_rollups') {
            // A fingerprint's labels describe the fingerprint, not the hour it
            // was seen in, so any of the day's rows carries the same pair.
            $query->addSelect([$this->max('label'), $this->max('sublabel'), $this->max('last_seen_at')]);
        }
    }

    private function sum(string $column): Expression
    {
        return DB::raw('COALESCE(SUM('.$this->wrap($column).'), 0) as '.$this->wrap($column));
    }

    private function max(string $column): Expression
    {
        return DB::raw('MAX('.$this->wrap($column).') as '.$this->wrap($column));
    }

    private function min(string $column): Expression
    {
        return DB::raw('MIN('.$this->wrap($column).') as '.$this->wrap($column));
    }

    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    /**
     * Write a rebuilt day, overwriting whatever was there.
     *
     * `upsert()` replaces the conflicting row rather than adding to it, which
     * is what makes a pass idempotent: the source rows are the whole truth
     * about the day, so replaying one can only produce the same numbers
     * again. Rows are chunked by the parameter budget of the narrowest
     * supported driver rather than by a fixed row count, since these tables
     * are between five and thirty columns wide.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     */
    private function replace(string $destination, array $rows, array $uniqueBy): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $update = array_values(array_diff($columns, $uniqueBy));
        $chunk = max(1, intdiv(self::MAX_BINDINGS, count($columns)));

        foreach (array_chunk($rows, $chunk) as $batch) {
            if ($update === []) {
                DB::table($destination)->insertOrIgnore($batch);

                continue;
            }

            DB::table($destination)->upsert($batch, $uniqueBy, $update);
        }
    }

    /**
     * Bound on placeholders in one statement, low enough for SQLite's
     * default `SQLITE_MAX_VARIABLE_NUMBER` as well as MySQL's packet limit.
     */
    private const MAX_BINDINGS = 20000;
}
