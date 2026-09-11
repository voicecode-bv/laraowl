<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository new releases are checked against. Forks that cut
    | their own releases should point this at their own repository.
    |
    */

    'repository' => env('LARAOWL_REPOSITORY', 'laraowl/laraowl'),

    /*
    |--------------------------------------------------------------------------
    | Update Checks
    |--------------------------------------------------------------------------
    |
    | LaraOwl periodically asks the GitHub releases API whether a newer version
    | has been published, and shows a banner to team owners when there is one.
    | Disable this to stop the instance from making outbound requests.
    |
    */

    'update_check' => [
        'enabled' => env('LARAOWL_UPDATE_CHECK', true),
        'cache_ttl' => (int) env('LARAOWL_UPDATE_CHECK_TTL', 21600),
        'timeout' => (int) env('LARAOWL_UPDATE_CHECK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Cache
    |--------------------------------------------------------------------------
    |
    | Every viewer of a project reloads on each ingest tick, and they all ask
    | the rollups for the same numbers over the same period. Those answers can
    | be shared for a few seconds instead of being recomputed per request.
    |
    | Left unset, this turns itself on only when the cache store is one the
    | workers share and that is quicker to ask than the database — Redis,
    | Memcached, DynamoDB or Octane. On the `database` store a cache read is
    | another query against the same database, so it stays off. Set
    | LARAOWL_DASHBOARD_CACHE to decide it yourself, and LARAOWL_DASHBOARD_CACHE_TTL
    | to trade freshness for load: it is how many seconds old a dashboard
    | number may be.
    |
    */

    'dashboard_cache' => [
        'enabled' => env('LARAOWL_DASHBOARD_CACHE'),
        'store' => env('LARAOWL_DASHBOARD_CACHE_STORE'),
        'ttl' => (int) env('LARAOWL_DASHBOARD_CACHE_TTL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Binaries
    |--------------------------------------------------------------------------
    |
    | Executables the `laraowl:update` command shells out to. Override these
    | when they are not resolvable on the PATH of the user running the update,
    | for example "/usr/local/bin/composer".
    |
    */

    'binaries' => [
        'git' => env('LARAOWL_GIT_BINARY', 'git'),
        'composer' => env('LARAOWL_COMPOSER_BINARY', 'composer'),
        'npm' => env('LARAOWL_NPM_BINARY', 'npm'),
    ],

];
