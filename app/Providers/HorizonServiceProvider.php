<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * The allowed addresses come from the HORIZON_ALLOWED_EMAILS environment
     * variable via config('horizon.allowed_emails'), so operators can be
     * changed without touching the repository.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            $email = strtolower(trim((string) ($user->email ?? '')));

            if ($email === '') {
                return false;
            }

            $allowed = array_map(
                static fn (string $allowed): string => strtolower(trim($allowed)),
                config('horizon.allowed_emails', [])
            );

            return in_array($email, $allowed, true);
        });
    }
}
