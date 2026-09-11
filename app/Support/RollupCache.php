<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * A short-lived cache in front of the dashboard's aggregate reads.
 *
 * The reads it fronts are already indexed aggregates over the rollups, so
 * this is not there to rescue a slow query: it is there because a monitoring
 * dashboard asks the same question over and over. Every viewer of a project
 * reloads on each ingest tick, and they all ask for the same numbers over the
 * same period — so one answer can serve the lot for a few seconds.
 *
 * That only holds on a store the workers share. On the `database` store the
 * cache read is a query against the same database the aggregate came from,
 * and on `file` or `array` it is either slower or not shared at all, so the
 * default is to stay out of the way until an operator points the cache at
 * something like Redis. Setting `LARAOWL_DASHBOARD_CACHE` decides it
 * explicitly, either way.
 */
final class RollupCache
{
    /**
     * Cache drivers that are shared between workers and quick enough to be
     * worth asking before the database.
     */
    private const SHARED_DRIVERS = ['redis', 'memcached', 'dynamodb', 'octane'];

    public function enabled(): bool
    {
        $configured = config('laraowl.dashboard_cache.enabled');

        if ($configured !== null) {
            return (bool) $configured && $this->ttl() > 0;
        }

        return $this->ttl() > 0 && in_array($this->driver(), self::SHARED_DRIVERS, true);
    }

    /**
     * Answer from the cache when there is one to answer from, and otherwise
     * resolve the value and keep it for the configured few seconds.
     *
     * @template TValue
     *
     * @param  array<string, mixed>  $context  everything the value depends on
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $name, array $context, Closure $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        return $this->store()->remember($this->key($name, $context), $this->ttl(), $callback);
    }

    /**
     * How long an answer may be reused, in seconds.
     */
    public function ttl(): int
    {
        return (int) config('laraowl.dashboard_cache.ttl', 0);
    }

    /**
     * The cache key for a read: its name plus everything it depends on, so a
     * different scope, period or custom range can never read another's answer.
     *
     * @param  array<string, mixed>  $context
     */
    private function key(string $name, array $context): string
    {
        return 'laraowl:rollups:'.$name.':'.sha1(json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function store(): Repository
    {
        return Cache::store(config('laraowl.dashboard_cache.store'));
    }

    private function driver(): ?string
    {
        $store = config('laraowl.dashboard_cache.store') ?? config('cache.default');

        return config('cache.stores.'.$store.'.driver');
    }
}
