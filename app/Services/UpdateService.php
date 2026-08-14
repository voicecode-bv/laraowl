<?php

namespace App\Services;

use App\Support\Release;
use App\Support\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use function Illuminate\Support\defer;

class UpdateService
{
    /**
     * Where the last known release is cached, as a plain array. The cache
     * refuses to unserialize objects, so a Release is never stored directly.
     *
     * @see config/cache.php
     */
    public const CACHE_KEY = 'laraowl.update.latest';

    /**
     * Held while a deferred check is pending, so a cold cache does not make
     * every incoming request queue up its own call to GitHub.
     */
    public const LOCK_KEY = 'laraowl.update.checking';

    /**
     * Get the release the instance should update to, if there is one.
     *
     * This only ever reads the cache, so it is safe to call on the request
     * path. When the cache is cold the check is deferred until after the
     * response has been sent, and the banner appears on the next request.
     */
    public function pendingUpdate(): ?Release
    {
        if (! $this->enabled()) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        // Anything that is not a payload this version wrote — never checked, or
        // written by an older release — means the answer is not yet known.
        if (! is_array($cached) || ! array_key_exists('release', $cached)) {
            $this->deferRefresh();

            return null;
        }

        $payload = $cached['release'];

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            $this->deferRefresh();

            return null;
        }

        $release = Release::fromArray($payload);

        return $release?->isNewerThanCurrent() ? $release : null;
    }

    /**
     * Ask GitHub for the latest release and cache the answer.
     *
     * Returns the release regardless of whether it is newer than the running
     * version, or null when the check could not be completed.
     */
    public function refresh(): ?Release
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'LaraOwl/'.Version::current(),
            ])
                ->timeout(config('laraowl.update_check.timeout'))
                ->get("https://api.github.com/repos/{$this->repository()}/releases/latest");

            if ($response->failed()) {
                Log::warning('LaraOwl update check failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $release = Release::fromGitHub($response->json() ?? []);
        } catch (\Exception $e) {
            Log::warning('LaraOwl update check failed: '.$e->getMessage());

            return null;
        }

        Cache::put(
            self::CACHE_KEY,
            ['release' => $release?->toArray()],
            (int) config('laraowl.update_check.cache_ttl'),
        );

        Cache::forget(self::LOCK_KEY);

        return $release;
    }

    /**
     * The version this instance is currently running.
     */
    public function currentVersion(): string
    {
        return Version::current();
    }

    /**
     * Determine whether update checking is enabled for this instance.
     */
    public function enabled(): bool
    {
        return (bool) config('laraowl.update_check.enabled');
    }

    /**
     * The repository releases are checked against.
     */
    public function repository(): string
    {
        return (string) config('laraowl.repository');
    }

    /**
     * Schedule a refresh once the current response has been sent, unless one
     * is already pending.
     */
    protected function deferRefresh(): void
    {
        if (! Cache::add(self::LOCK_KEY, true, 300)) {
            return;
        }

        defer(fn () => $this->refresh());
    }
}
