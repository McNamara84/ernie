<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Observers\ResourceObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Configure rate limiters
        $this->configureRateLimiting();

        // Define authorization gates
        $this->defineGates();
    }

    /**
     * Configure the rate limiters for the application.
     */
    private function configureRateLimiting(): void
    {
        // Rate limiter for ORCID API endpoints
        // Allows 30 requests per minute per IP address
        RateLimiter::for('orcid-api', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }

    /**
     * Define the authorization gates for the application.
     *
     * Gates provide a simple, closure-based approach to authorization.
     * They are used for global permissions that are not tied to a specific model.
     *
     * Role-based access control (Issue #379):
     * - Admin: Full access to all areas (Logs, Old Datasets, Statistics, Users, Editor Settings)
     * - Group Leader: Statistics, Users, Editor Settings (no Logs, no Old Datasets)
     * - Curator: No access to any administrative features
     * - Beginner: Same as Curator, additionally restricted to test DOI registration only
     */
    private function defineGates(): void
    {
        // Access to Logs page (Admin only)
        Gate::define('access-logs', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Access to Old Datasets page (Admin only)
        Gate::define('access-old-datasets', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Access to Statistics page (Admin, Group Leader)
        Gate::define('access-statistics', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Access to User Management page (Admin, Group Leader)
        Gate::define('access-users', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Access to Editor Settings page (Admin, Group Leader)
        Gate::define('access-editor-settings', function (User $user): bool {
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
