<?php

/*
|--------------------------------------------------------------------------
| Guard against the global defer() being claimed by an extension
|--------------------------------------------------------------------------
|
| Laravel registers its global defer() helper conditionally:
| Illuminate/Foundation/helpers.php wraps the declaration in
| if (! function_exists('defer')). A PHP extension that claims the name at
| startup therefore wins, and an unqualified defer() call inside a namespaced
| class reaches the extension instead of Laravel.
|
| The swoole extension does exactly that, and ships enabled by default on
| Laravel Forge servers (it is part of the standard php{version}-* install
| set, whether or not Octane is used). Its defer() throws
| Swoole\Error: API must be called in the coroutine when there is no
| coroutine, which is fatal, kills the PHP-FPM worker, and reaches the
| visitor as a 502.
|
| This guard is deliberately static. A behavioural test cannot catch the
| problem, because without the extension loaded the unqualified call resolves
| to Laravel's helper and passes. Importing the namespaced function is what
| the framework itself does everywhere it calls defer() -- see
| Illuminate\Cache\Repository, the Concurrency drivers and Http\Client\Batch.
|
*/

test('every unqualified defer() call imports the namespaced helper', function () {
    $appPath = dirname(__DIR__, 2).'/app';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        // A bare defer(...) call: not $x->defer(), not Foo::defer(),
        // not \defer(), and not a function declaration.
        $callsDefer = (bool) preg_match('/(?<![\w$>\\\\:])(?<!function )defer\s*\(/', $contents);

        if (! $callsDefer) {
            continue;
        }

        if (! str_contains($contents, 'use function Illuminate\Support\defer;')) {
            $offenders[] = str_replace($appPath.'/', 'app/', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
