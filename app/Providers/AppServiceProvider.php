<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Observers\ResourceObserver;
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
        // Register model observers
        Resource::observe(ResourceObserver::class);

        // Define authorization gates
        $this->defineGates();
    }

    /**
     * Define the authorization gates for the application.
     *
     * Gates provide a simple, closure-based approach to authorization.
     * They are used for global permissions that are not tied to a specific model.
     */
    private function defineGates(): void
    {
        // Access to the Administration section (Old Datasets, Statistics, Users, Logs)
        Gate::define('access-administration', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Manage users (create, update roles, deactivate, etc.)
        Gate::define('manage-users', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Register DOIs in production mode (Beginners are restricted to test mode)
        Gate::define('register-production-doi', function (User $user): bool {
            return $user->role !== UserRole::BEGINNER;
        });

        // Delete application logs (Admin only)
        Gate::define('delete-logs', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });
    }
}
