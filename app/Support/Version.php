<?php

namespace App\Support;

class Version
{
    /**
     * The version this instance of LaraOwl is running.
     *
     * Bumped by hand on each tagged release — mirrors how
     * Illuminate\Foundation\Application::VERSION is maintained, instead of
     * a manual "version" key in composer.json (which composer validate
     * --strict warns against for packages, and which isn't authoritative
     * once someone patches the code without re-tagging).
     */
    const CURRENT = '1.1.2';

    /**
     * Get the version this instance of LaraOwl is running.
     */
    public static function current(): string
    {
        return static::CURRENT;
    }

    /**
     * Normalize a version string for comparison, dropping any "v" prefix.
     */
    public static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /**
     * Determine whether the given version is newer than the one running.
     */
    public static function isNewerThanCurrent(string $version): bool
    {
        return version_compare(
            static::normalize($version),
            static::normalize(static::current()),
            '>',
        );
    }
}
