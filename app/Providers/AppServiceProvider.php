<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Jobs\DiscoverRelationsJob;
use App\Jobs\ImportFromDataCiteJob;
use App\Jobs\UpdatePidJob;
use App\Jobs\UpdateThesaurusJob;
use App\Listeners\MarkContactMessageAsSent;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\User;
use App\Observers\LandingPageObserver;
use App\Observers\ResourceAssessmentObserver;
use App\Observers\ResourceObserver;
use App\Observers\ResourceTypeObserver;
use App\Observers\SubjectObserver;
use App\Services\BotProtection\BotClassifierService;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteServiceInterface;
use App\Services\PortalKeywordCacheInvalidationService;
use App\Services\RorLookupService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(PortalKeywordCacheInvalidationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        Resource::observe(ResourceObserver::class);
        ResourceAssessment::observe(ResourceAssessmentObserver::class);
        ResourceType::observe(ResourceTypeObserver::class);
        LandingPage::observe(LandingPageObserver::class);
        Subject::observe(SubjectObserver::class);
        Event::listen(MessageSent::class, MarkContactMessageAsSent::class);

        // Configure rate limiters
        $this->configureRateLimiting();

        // Define authorization gates
        $this->defineGates();

        // Route jobs to dedicated queues to prevent long-running imports
        // from blocking shorter vocabulary/PID update tasks
        Queue::route(ImportFromDataCiteJob::class, queue: 'imports');
        Queue::route(UpdatePidJob::class, queue: 'vocabularies');
        Queue::route(UpdateThesaurusJob::class, queue: 'vocabularies');
        Queue::route(DiscoverRelationsJob::class, queue: 'default');
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

        // Rate limiter for OAI-PMH harvesting endpoint
        // Allows 120 requests per minute per IP (harvester-friendly but prevents abuse)
        RateLimiter::for('oai-pmh', function (Request $request) {
            return Limit::perMinute(120)->by((string) $request->ip());
        });

        RateLimiter::for('public-portal', function (Request $request) {
            return $this->publicDiscoveryLimit($request, 'portal', 'public_portal_per_minute', 20);
        });

        RateLimiter::for('public-landing-page', function (Request $request) {
            return $this->publicDiscoveryLimit($request, 'landing-page', 'public_landing_per_minute', 60);
        });

        RateLimiter::for('public-landing-jsonld', function (Request $request) {
            return $this->publicDiscoveryLimit($request, 'landing-jsonld', 'public_landing_jsonld_per_minute', 30);
        });
    }

    private function publicDiscoveryLimit(Request $request, string $surface, string $publicLimitKey, int $defaultAttempts): Limit|Unlimited
    {
        if (! (bool) config('bot_protection.enabled', true)) {
            return Limit::none();
        }

        $classifier = $this->app->make(BotClassifierService::class);
        $limit = $classifier->isKnownAiBot($request)
            ? (int) config('bot_protection.limits.ai_bot_public_per_minute', 6)
            : (int) config("bot_protection.limits.{$publicLimitKey}", $defaultAttempts);

        return Limit::perMinute(max(1, $limit))->by($classifier->rateLimitKey($request, $surface));
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

        // Register DOIs in the effective DataCite mode (Beginners are restricted to test mode)
        Gate::define('register-doi', function (User $user): bool {
            return in_array($user->role, [
                UserRole::ADMIN,
                UserRole::GROUP_LEADER,
                UserRole::CURATOR,
                UserRole::BEGINNER,
            ], true);
        });

        // Register DOIs in production mode (Beginners are restricted to test mode)
        Gate::define('register-production-doi', function (User $user): bool {
            return $user->role !== UserRole::BEGINNER;
        });

        // Delete application logs (Admin only)
        Gate::define('delete-logs', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Manage thesauri (check & trigger updates) - Admin and Group Leader
        Gate::define('manage-thesauri', function (User $user): bool {
            return in_array($user->role, [UserRole::ADMIN, UserRole::GROUP_LEADER], true);
        });

        // Delete all resources (bulk cleanup) - Admin only
        Gate::define('delete-all-resources', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });

        // Manage landing pages (create and update; delete is gated separately)
        Gate::define('manage-landing-pages', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER
                || $user->role === UserRole::CURATOR
                || $user->role === UserRole::BEGINNER;
        });

        // Delete draft landing pages (published landing pages remain protected in the controller)
        Gate::define('delete-landing-pages', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER
                || $user->role === UserRole::CURATOR;
        });

        // Manage landing page templates (create, clone, update, delete)
        // Only Admin and Group Leader can manage templates
        Gate::define('manage-landing-page-templates', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Access to Assistance page (Admin, Group Leader)
        Gate::define('access-assistance', function (User $user): bool {
            return $user->role === UserRole::ADMIN
                || $user->role === UserRole::GROUP_LEADER;
        });

        // Access to Assessment page (Admin only)
        Gate::define('access-assessment', function (User $user): bool {
            return $user->role === UserRole::ADMIN;
        });
    }
}
