<?php

namespace App\Services;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;

/**
 * Computes the dashboard's aggregates at write time.
 */
class RollupWriter
{
    /**
     * Upper bounds of the latency histogram buckets, in microseconds.
     */
    public const LATENCY_BOUNDARIES = [1000, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000, 2500000, 5000000, 10000000];

    /**
     * Types whose users are tracked for distinct-user counts.
     */
    public const USER_TRACKED_TYPES = ['request', 'exception', 'query', 'queued-job'];

    public const GROUP_USER_TYPES = ['exception'];

    /**
     * The prefix the client gives anonymous visitors instead of a null user.
     */
    public const GUEST_ID_PREFIX = 'guest_';

    /**
     * Counters that accumulate by addition.
     *
     * @return list<string>
     */
    public static function additiveColumns(): array
    {
        return array_merge([
            'count',
            'ok_count',
            'client_error_count',
            'server_error_count',
            'neutral_count',
            'hits',
            'misses',
            'writes',
            'deletes',
            'authed_count',
            'sum_duration',
            'count_duration',
        ], static::latencyColumns());
    }

    /**
     * @return list<string>
     */
    public static function latencyColumns(): array
    {
        $columns = array_map(fn (int $boundary) => 'lat_le_'.$boundary, static::LATENCY_BOUNDARIES);
        $columns[] = 'lat_le_inf';

        return $columns;
    }

    /**
     * Fold a batch of ingested records into the rollup tables.
     *
     * @param  list<array{type: string, payload: array<string, mixed>, fingerprint: string|null, created_at: CarbonInterface}>  $batch
     */
    public function record(Project $project, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $rollups = [];
        $groups = [];
        $groupUsers = [];
        $userBuckets = [];
        $ipBuckets = [];

        foreach ($batch as $entry) {
            $type = $entry['type'];
            $payload = $entry['payload'];
            $seenAt = $entry['created_at'];
            $bucket = $this->bucketFor($seenAt);
            $hour = $this->hourFor($seenAt);
            $deltas = $this->deltasFor($type, $payload);

            $key = $type.'@'.$bucket;
            $rollups[$key] = $this->mergeDeltas(
                $rollups[$key] ?? $this->emptyRow($project->id, $type, $bucket),
                $deltas,
            );

            $fingerprint = $entry['fingerprint'] ?? null;

            if ($fingerprint) {
                $groupKey = $type.'@'.$hour.'@'.$fingerprint;
                [$label, $sublabel] = $this->groupLabelsFor($type, $payload);

                $groups[$groupKey] = $this->mergeDeltas(
                    $groups[$groupKey] ?? $this->emptyGroupRow($project->id, $type, $hour, $fingerprint),
                    $deltas,
                );

                $groups[$groupKey]['label'] = $label;
                $groups[$groupKey]['sublabel'] = $sublabel;
                $groups[$groupKey]['last_seen_at'] = max(
                    $groups[$groupKey]['last_seen_at'] ?? '',
                    $seenAt->format('Y-m-d H:i:s'),
                );
            }

            $ip = $this->ipFor($payload);

            if ($ip !== null) {
                $ipRow = $type.'@'.$hour.'@'.$ip;

                $ipBuckets[$ipRow] ??= [
                    'project_id' => $project->id,
                    'type' => $type,
                    'bucket' => $hour,
                    'ip' => $ip,
                    'count' => 0,
                ];

                $ipBuckets[$ipRow]['count']++;
            }

            $userKey = $this->userKeyFor($type, $payload);

            if ($userKey !== null && $fingerprint && in_array($type, static::GROUP_USER_TYPES, true)) {
                $groupUsers[$type.'@'.$hour.'@'.$fingerprint.'@'.$userKey] = [
                    'project_id' => $project->id,
                    'type' => $type,
                    'bucket' => $hour,
                    'group_key' => $fingerprint,
                    'user_key' => $userKey,
                ];
            }

            if ($userKey !== null) {
                $userRow = $type.'@'.$hour.'@'.$userKey;

                $userBuckets[$userRow] ??= [
                    'project_id' => $project->id,
                    'type' => $type,
                    'bucket' => $hour,
                    'user_key' => $userKey,
                    'count' => 0,
                    'error_count' => 0,
                    'last_seen_at' => null,
                ];

                $userBuckets[$userRow]['count']++;
                $userBuckets[$userRow]['error_count'] += ($deltas['client_error_count'] ?? 0) + ($deltas['server_error_count'] ?? 0);
                $userBuckets[$userRow]['last_seen_at'] = max(
                    $userBuckets[$userRow]['last_seen_at'] ?? '',
                    $seenAt->format('Y-m-d H:i:s'),
                );
            }
        }

        $this->upsertIncrement(
            'record_rollups',
            array_values($rollups),
            conflict: ['project_id', 'type', 'bucket'],
            additive: static::additiveColumns(),
            maxColumns: ['max_duration'],
            minColumns: ['min_duration'],
        );

        $this->upsertIncrement(
            'record_group_rollups',
            array_values($groups),
            conflict: ['project_id', 'type', 'bucket', 'group_key'],
            additive: static::additiveColumns(),
            maxColumns: ['max_duration', 'last_seen_at'],
            minColumns: ['min_duration'],
            overwrite: ['label', 'sublabel'],
        );

        $this->upsertIncrement(
            'record_user_buckets',
            array_values($userBuckets),
            conflict: ['project_id', 'type', 'bucket', 'user_key'],
            additive: ['count', 'error_count'],
            maxColumns: ['last_seen_at'],
        );

        $this->upsertIncrement(
            'record_ip_buckets',
            array_values($ipBuckets),
            conflict: ['project_id', 'type', 'bucket', 'ip'],
            additive: ['count'],
        );

        if ($groupUsers !== []) {
            DB::table('record_group_user_buckets')->insertOrIgnore(array_values($groupUsers));
        }
    }

    /**
     * The two columns a list page shows for a group, by record type.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string|null, 1: string|null}
     */
    public function groupLabelsFor(string $type, array $payload): array
    {
        $first = fn (array $keys, ?string $fallback = null) => $this->firstString($payload, $keys) ?? $fallback;

        [$label, $sublabel] = match ($type) {
            'request' => [$first(['method'], 'GET'), $first(['route_path', 'path'], '/')],
            'exception' => [$first(['class']), $first(['message'])],
            'query' => [$first(['connection'], 'mysql'), $first(['sql'])],
            'job-attempt', 'queued-job' => [$first(['name', 'job', 'job_class'], 'Unknown Job'), null],
            'command' => [$first(['command', 'name'], 'Unknown Command'), null],
            'scheduled-task' => [$first(['command', 'name'], 'Unknown Task'), $first(['cron', 'expression', 'schedule'])],
            'outgoing-request' => [$first(['host']), null],
            'cache-event' => [$first(['key']), $first(['store'])],
            'notification' => [$first(['class']), $first(['channel'])],
            'mail' => [$first(['class']), $first(['mailer'])],
            default => [null, null],
        };

        return [
            $label === null ? null : mb_substr($label, 0, 255),
            $sublabel,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    protected function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_scalar($value) && ! is_bool($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Floor a timestamp to the minute it belongs to.
     */
    public function bucketFor(CarbonInterface $at): string
    {
        return $at->copy()->startOfMinute()->format('Y-m-d H:i:s');
    }

    /**
     * Floor a timestamp to the hour, for the user buckets.
     */
    public function hourFor(CarbonInterface $at): string
    {
        return $at->copy()->startOfHour()->format('Y-m-d H:i:s');
    }

    /**
     * The per-record contribution to its bucket's counters.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, float|int|null>
     */
    public function deltasFor(string $type, array $payload): array
    {
        $deltas = ['count' => 1];

        $status = $this->resolvedStatusFor($type, $payload);

        if ($status !== null) {
            if ($status >= 500) {
                $deltas['server_error_count'] = 1;
            } elseif ($status >= 400) {
                $deltas['client_error_count'] = 1;
            } elseif ($status >= 100) {
                $deltas['ok_count'] = 1;
            }
        }

        if ($type === 'exception') {
            $deltas['server_error_count'] = 1;
        }

        if ($type === 'job-attempt' || $type === 'queued-job') {
            $this->applyJobStatus($deltas, $payload);
        }

        if ($type === 'command') {
            $this->applyExitCode($deltas, $payload);
        }

        if ($type === 'scheduled-task') {
            $this->applyScheduledTask($deltas, $payload);
        }

        if ($type === 'cache-event') {
            $this->applyCacheType($deltas, $payload);
        }

        if ($type === 'notification' && $this->notificationFailed($payload)) {
            $deltas['server_error_count'] = 1;
        }

        if ($type === 'mail' && ($payload['mailer'] ?? null) !== 'log') {
            $deltas['ok_count'] = 1;
        }

        if ($type === 'request' && $this->userKeyFor($type, $payload) !== null) {
            $deltas['authed_count'] = 1;
        }

        $duration = $this->numeric($payload['duration'] ?? null);

        if ($duration !== null) {
            $deltas['sum_duration'] = $duration;
            $deltas['count_duration'] = 1;
            $deltas['max_duration'] = $duration;
            $deltas['min_duration'] = $duration;
            $deltas[$this->latencyColumn($duration)] = 1;
        }

        return $deltas;
    }

    /**
     * The distinct key for the user behind a record, or null when the record
     * belongs to nobody.
     *
     * @param  array<string, mixed>  $payload
     */
    public function userKeyFor(string $type, array $payload): ?string
    {
        if (! in_array($type, static::USER_TRACKED_TYPES, true)) {
            return null;
        }

        $user = $payload['user'] ?? null;

        if (is_array($user)) {
            $user = $user['id'] ?? null;
        }

        if ($user === null || $user === '') {
            return null;
        }

        $user = (string) $user;

        if (str_starts_with($user, static::GUEST_ID_PREFIX)) {
            return null;
        }

        return substr($user, 0, 64);
    }

    /**
     * The user a raw record belongs to, as stored in the indexed `user_key`
     * column.
     *
     * A `user` record *is* the user, so its identifier sits at the payload
     * root rather than under `user` like every other type. Keying those rows
     * too is what lets the name/email lookup behind the user panels filter on
     * the indexed column instead of on a JSON expression.
     *
     * @param  array<string, mixed>  $payload
     */
    public function rawUserKeyFor(string $type, array $payload): ?string
    {
        $user = $type === 'user'
            ? $payload['id'] ?? null
            : $payload['user'] ?? null;

        if (is_array($user)) {
            $user = $user['id'] ?? null;
        }

        if ($user === null || $user === '' || ! is_scalar($user)) {
            return null;
        }

        return substr((string) $user, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ipFor(array $payload): ?string
    {
        $ip = $payload['ip'] ?? null;

        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function traceIdFor(array $payload): ?string
    {
        $traceId = $payload['trace_id'] ?? null;

        return is_string($traceId) && $traceId !== '' ? substr($traceId, 0, 64) : null;
    }

    /**
     * The searchable text for the `records.message` FULLTEXT column.
     *
     * For logs the level is folded in, so searching "error" still finds
     * error-level entries the way the old whole-payload LIKE did.
     *
     * @param  array<string, mixed>  $payload
     */
    public function messageFor(string $type, array $payload): ?string
    {
        $message = $payload['message'] ?? null;
        $message = is_string($message) ? $message : '';

        if ($type === 'log') {
            $level = $payload['level'] ?? '';
            $message = trim((is_string($level) ? $level : '').' '.$message);
        }

        return $message === '' ? null : mb_substr($message, 0, 1000);
    }

    /**
     * The histogram column a duration falls into.
     */
    public function latencyColumn(float $milliseconds): string
    {
        foreach (static::LATENCY_BOUNDARIES as $boundary) {
            if ($milliseconds <= $boundary) {
                return 'lat_le_'.$boundary;
            }
        }

        return 'lat_le_inf';
    }

    /**
     * Add each row's counters onto its bucket, creating the bucket if absent.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $conflict
     * @param  list<string>  $additive
     * @param  list<string>  $maxColumns
     * @param  list<string>  $minColumns
     * @param  list<string>  $overwrite
     */
    public function upsertIncrement(
        string $table,
        array $rows,
        array $conflict,
        array $additive,
        array $maxColumns = [],
        array $minColumns = [],
        array $overwrite = [],
    ): void {
        if ($rows === []) {
            return;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $columns = array_keys($rows[0]);

        $wrapped = implode(', ', array_map(fn (string $column) => $grammar->wrap($column), $columns));
        $tuple = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $placeholders = implode(', ', array_fill(0, count($rows), $tuple));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = 'insert into '.$grammar->wrap($table)." ({$wrapped}) values {$placeholders} "
            .$this->conflictClause($connection->getDriverName(), $grammar, $table, $conflict, $additive, $maxColumns, $minColumns, $overwrite);

        $connection->statement($sql, $bindings);
    }

    /**
     * The driver-specific "add to the existing row" clause.
     *
     * @param  list<string>  $conflict
     * @param  list<string>  $additive
     * @param  list<string>  $maxColumns
     * @param  list<string>  $minColumns
     * @param  list<string>  $overwrite
     */
    protected function conflictClause(
        string $driver,
        Grammar $grammar,
        string $table,
        array $conflict,
        array $additive,
        array $maxColumns,
        array $minColumns,
        array $overwrite,
    ): string {
        $isMysql = $driver === 'mysql' || $driver === 'mariadb';

        $incoming = $isMysql
            ? fn (string $c) => 'values('.$grammar->wrap($c).')'
            : fn (string $c) => 'excluded.'.$grammar->wrap($c);

        $existing = $driver === 'pgsql'
            ? fn (string $c) => $grammar->wrap($table).'.'.$grammar->wrap($c)
            : fn (string $c) => $grammar->wrap($c);

        $assignments = array_map(
            fn (string $c) => $grammar->wrap($c).' = '.$existing($c).' + '.$incoming($c),
            $additive,
        );

        foreach ($overwrite as $column) {
            $assignments[] = $grammar->wrap($column).' = '.$incoming($column);
        }

        $maxFn = $isMysql || $driver === 'pgsql' ? 'greatest' : 'max';
        $minFn = $isMysql || $driver === 'pgsql' ? 'least' : 'min';

        foreach ($maxColumns as $column) {
            $assignments[] = $grammar->wrap($column).' = '.($driver === 'pgsql'
                ? $maxFn.'('.$existing($column).', '.$incoming($column).')'
                : $this->nullSafeExtreme($grammar, $maxFn, $column, $existing, $incoming));
        }

        foreach ($minColumns as $column) {
            $assignments[] = $grammar->wrap($column).' = '.($driver === 'pgsql'
                ? $minFn.'('.$existing($column).', '.$incoming($column).')'
                : $this->nullSafeExtreme($grammar, $minFn, $column, $existing, $incoming));
        }

        $target = implode(', ', array_map(fn (string $c) => $grammar->wrap($c), $conflict));

        return $isMysql
            ? 'on duplicate key update '.implode(', ', $assignments)
            : "on conflict ({$target}) do update set ".implode(', ', $assignments);
    }

    /**
     * `GREATEST(x, NULL)` is NULL on MySQL and SQLite; coalesce both sides.
     *
     * @param  callable(string): string  $existing
     * @param  callable(string): string  $incoming
     */
    protected function nullSafeExtreme(Grammar $grammar, string $function, string $column, callable $existing, callable $incoming): string
    {
        $left = 'coalesce('.$existing($column).', '.$incoming($column).')';
        $right = 'coalesce('.$incoming($column).', '.$existing($column).')';

        return $function.'('.$left.', '.$right.')';
    }

    /**
     * A zeroed rollup row, so every upsert binds the same column list.
     *
     * @return array<string, mixed>
     */
    protected function emptyRow(int $projectId, string $type, string $bucket): array
    {
        $row = [
            'project_id' => $projectId,
            'type' => $type,
            'bucket' => $bucket,
        ];

        foreach (static::additiveColumns() as $column) {
            $row[$column] = 0;
        }

        $row['max_duration'] = null;
        $row['min_duration'] = null;

        return $row;
    }

    /**
     * A zeroed group row. Column order must be stable across the batch, since
     * every row of one upsert binds the same column list.
     *
     * @return array<string, mixed>
     */
    protected function emptyGroupRow(int $projectId, string $type, string $hour, string $groupKey): array
    {
        $row = [
            'project_id' => $projectId,
            'type' => $type,
            'bucket' => $hour,
            'group_key' => $groupKey,
            'label' => null,
            'sublabel' => null,
        ];

        foreach (static::additiveColumns() as $column) {
            $row[$column] = 0;
        }

        $row['max_duration'] = null;
        $row['min_duration'] = null;
        $row['last_seen_at'] = null;

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, float|int|null>  $deltas
     * @return array<string, mixed>
     */
    protected function mergeDeltas(array $row, array $deltas): array
    {
        foreach ($deltas as $column => $value) {
            if ($column === 'max_duration') {
                $row[$column] = $row[$column] === null ? $value : max($row[$column], $value);
            } elseif ($column === 'min_duration') {
                $row[$column] = $row[$column] === null ? $value : min($row[$column], $value);
            } else {
                $row[$column] += $value;
            }
        }

        return $row;
    }

    /**
     * The HTTP status a record carries, if it has one.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function resolvedStatusFor(string $type, array $payload): ?float
    {
        if ($type === 'request') {
            return $this->numeric($payload['status_code'] ?? null);
        }

        if ($type === 'outgoing-request') {
            return $this->numeric($payload['status_code'] ?? null) ?? $this->numeric($payload['status'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $deltas
     * @param  array<string, mixed>  $payload
     */
    protected function applyJobStatus(array &$deltas, array $payload): void
    {
        match ($payload['status'] ?? null) {
            'processed' => $deltas['ok_count'] = 1,
            'failed' => $deltas['server_error_count'] = 1,
            'released' => $deltas['neutral_count'] = 1,
            default => null,
        };
    }

    /**
     * @param  array<string, float|int|null>  $deltas
     * @param  array<string, mixed>  $payload
     */
    protected function applyExitCode(array &$deltas, array $payload): void
    {
        $exitCode = $this->numeric($payload['exit_code'] ?? null);

        if ($exitCode === null) {
            return;
        }

        if ((int) $exitCode === 0) {
            $deltas['ok_count'] = 1;
        } else {
            $deltas['server_error_count'] = 1;
        }
    }

    /**
     * @param  array<string, float|int|null>  $deltas
     * @param  array<string, mixed>  $payload
     */
    protected function applyScheduledTask(array &$deltas, array $payload): void
    {
        match ($payload['status'] ?? null) {
            'skipped' => $deltas['neutral_count'] = 1,
            'processed' => $deltas['ok_count'] = 1,
            'failed' => $deltas['server_error_count'] = 1,
            default => null,
        };
    }

    /**
     * @param  array<string, float|int|null>  $deltas
     * @param  array<string, mixed>  $payload
     */
    protected function applyCacheType(array &$deltas, array $payload): void
    {
        $cacheType = $payload['type'] ?? null;

        if ($cacheType === 'hit') {
            $deltas['hits'] = 1;
            $deltas['ok_count'] = 1;
        } elseif ($cacheType === 'miss') {
            $deltas['misses'] = 1;
            $deltas['server_error_count'] = 1;
        } elseif ($cacheType === 'write') {
            $deltas['writes'] = 1;
        } elseif ($cacheType === 'delete') {
            $deltas['deletes'] = 1;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function notificationFailed(array $payload): bool
    {
        return ($payload['status'] ?? null) === 'failed' || ($payload['failed'] ?? false) === true;
    }

    /**
     * Read a value only when it really is a number.
     */
    protected function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
