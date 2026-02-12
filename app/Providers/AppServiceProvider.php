<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Observers\ResourceObserver;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteServiceInterface;
use App\Services\RorLookupService;
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
        // Bind DataCiteServiceInterface to the real implementation
        // This binding is overridden by TestingServiceProvider in testing environment
        $this->app->bind(DataCiteServiceInterface::class, DataCiteRegistrationService::class);

        // RorLookupService is a singleton so the ROR JSON file is loaded at most once per request
        $this->app->singleton(RorLookupService::class);
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

        // Rate limiter for DOI validation endpoint
        // Allows 60 requests per minute per authenticated user
        // Uses IP fallback for defensive programming in case auth middleware changes
        RateLimiter::for('doi-validation', function (Request $request) {
            $user = $request->user();
            $identifier = $user !== null ? $user->id : $request->ip();

            return Limit::perMinute(60)->by((string) $identifier);
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

        // Manage thesauri (trigger updates) - Admin only
        Gate::define('manage-thesauri', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Delete all resources (bulk cleanup) - Admin only
        Gate::define('delete-all-resources', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Manage landing pages (create, update, delete)
        // Beginners can only view landing pages, not manage them
        Gate::define('manage-landing-pages', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER
                || $user->role === UserRole::CURATOR;
        });
    }
}
