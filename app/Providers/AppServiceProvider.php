<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (?User $user, string $ability) {
            return $user && $user->hasRole('admin') ? true : null;
        });

        Gate::define('view-dashboard', fn (User $user) => $user->hasRole('admin'));
        Gate::define('export-companies', fn (User $user) => $user->hasRole('admin'));
        Gate::define('search-companies', fn (User $user) => $user->hasRole('admin'));
        Gate::define('manage-notifications', fn (User $user) => $user->hasRole('admin'));
    }
}
