<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeder for creating Playwright test users in Docker development environment.
 *
 * Usage: php artisan db:seed --class=PlaywrightTestSeeder
 *
 * All E2E test fixtures use a "Playwright:" prefix for easy identification.
 * Fixtures may include additional descriptive text in parentheses to clarify their purpose
 * (e.g., "Playwright: Curation Resource (no landing page)").
 *
 * SECURITY WARNING: Creates users with simple passwords ('password').
 * Only use in development environments, never in production!
 */
class PlaywrightTestSeeder extends Seeder
{
    /**
     * Default password for all test users.
     * Change this single value to update all test user passwords.
     */
    private const TEST_PASSWORD = 'password';

    private const PLAYWRIGHT_PUBLISHED_RESOURCE_DOI = '10.1234/playwright-published';

    // Review fixture must have a DOI + an unpublished landing page to surface as publicstatus=review.
    // Avoid "review" substring in DOI to prevent Playwright :text() selectors from matching
    // the DOI badge when searching for "Review" status badges in the UI.
    private const PLAYWRIGHT_REVIEW_RESOURCE_DOI = '10.1234/playwright-qa';

    // Legacy: older seeds created a "review" fixture with a DOI. Keep this for cleanup/idempotency.
    private const LEGACY_PLAYWRIGHT_REVIEW_RESOURCE_DOI = '10.1234/playwright-review';

    /**
     * Hashed password, computed once for efficiency.
     */
    private string $hashedPassword;

    public function run(): void
    {
        // Seed essential lookup tables to keep the dev stack close to stage/prod.
        // This also ensures UI dropdowns (e.g. Rights/SPDX licenses) are populated for E2E tests.
        // VERIFIED: All called seeders use firstOrCreate/updateOrCreate patterns:
        // - ResourceTypeSeeder, TitleTypeSeeder, DateTypeSeeder, DescriptionTypeSeeder,
        //   ContributorTypeSeeder, IdentifierTypeSeeder, RelationTypeSeeder,
        //   FunderIdentifierTypeSeeder, LanguageSeeder use firstOrCreate
        // - RightsSeeder, PublisherSeeder use updateOrCreate
        // If a new seeder is added, verify it's idempotent before including here.
        try {
            $this->call([
                ResourceTypeSeeder::class,
                TitleTypeSeeder::class,
                DateTypeSeeder::class,
                DescriptionTypeSeeder::class,
                ContributorTypeSeeder::class,
                IdentifierTypeSeeder::class,
                RelationTypeSeeder::class,
                FunderIdentifierTypeSeeder::class,
                LanguageSeeder::class,
                RightsSeeder::class,
                PublisherSeeder::class,
            ]);
            $this->command->info('Lookup table seeders completed successfully.');
        } catch (\Throwable $e) {
            $this->command->error('Failed to seed lookup tables: '.$e->getMessage());
            throw $e;
        }

        // Hash password once for all users
        $this->hashedPassword = bcrypt(self::TEST_PASSWORD);

        // Create test user for Playwright tests
        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => $this->hashedPassword,
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => $this->hashedPassword,
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create group leader
        User::firstOrCreate(
            ['email' => 'groupleader@example.com'],
            [
                'name' => 'Group Leader',
                'password' => $this->hashedPassword,
                'role' => UserRole::GROUP_LEADER,
                'email_verified_at' => now(),
            ]
        );

        // Create curator
        User::firstOrCreate(
            ['email' => 'curator@example.com'],
            [
                'name' => 'Curator User',
                'password' => $this->hashedPassword,
                'role' => UserRole::CURATOR,
                'email_verified_at' => now(),
            ]
        );

        // Create beginner
        User::firstOrCreate(
            ['email' => 'beginner@example.com'],
            [
                'name' => 'Beginner User',
                'password' => $this->hashedPassword,
                'role' => UserRole::BEGINNER,
                'email_verified_at' => now(),
            ]
        );

        $this->seedPlaywrightE2eResources($testUser);

        $this->command->info('Playwright test users created successfully!');
    }

    /** @return array<string, mixed>
     */
    private function getAuditedResourceAttributes(User $user): array
    {
        $resourceAttributes = [];

        if (Schema::hasColumn('resources', 'created_by_user_id')) {
            $resourceAttributes['created_by_user_id'] = $user->id;
        }

        if (Schema::hasColumn('resources', 'updated_by_user_id')) {
            $resourceAttributes['updated_by_user_id'] = $user->id;
        }

        return $resourceAttributes;
    }

    private function seedPlaywrightE2eResources(User $testUser): void
    {
        $resourceAttributes = $this->getAuditedResourceAttributes($testUser);

        // Cleanup: normalize older review DOI to the new, non-colliding DOI.
        $legacyReviewResource = Resource::query()->where('doi', self::LEGACY_PLAYWRIGHT_REVIEW_RESOURCE_DOI)->first();
        if ($legacyReviewResource) {
            $legacyReviewResource->doi = self::PLAYWRIGHT_REVIEW_RESOURCE_DOI;
            $legacyReviewResource->save();
        }

        // 1) Published resource (shown with "Published" status)
        $publishedResource = Resource::query()
            ->where('doi', self::PLAYWRIGHT_PUBLISHED_RESOURCE_DOI)
            ->first();

        if (! $publishedResource) {
            $publishedResource = Resource::factory()
                ->withDoi(self::PLAYWRIGHT_PUBLISHED_RESOURCE_DOI)
                ->create($resourceAttributes);

            Title::factory()->create([
                'resource_id' => $publishedResource->id,
                'value' => 'Playwright: Published Resource',
            ]);

            ResourceCreator::factory()->create([
                'resource_id' => $publishedResource->id,
                'position' => 1,
            ]);
        }

        // Use updateOrCreate to ensure doi_prefix is correctly set on every seeder run.
        // This intentionally overwrites any manual changes because Playwright tests
        // require consistent, predictable test data. If preserving manual changes
        // is needed for a different fixture, use firstOrCreate instead.
        LandingPage::query()->updateOrCreate(
            ['slug' => 'playwright-published'],
            [
                'resource_id' => $publishedResource->id,
                'template' => 'default_gfz',
                'is_published' => true,
                'published_at' => now(),
                'doi_prefix' => self::PLAYWRIGHT_PUBLISHED_RESOURCE_DOI,
            ]
        );

        // 2) Review resource (shown with "Review" status)
        // Backend semantics: review requires BOTH DOI + landing page (is_published=false).
        // Prefer upgrading any legacy fixture with title "Playwright: Review Resource" into the canonical review fixture.
        $legacyReviewTitle = Title::query()->where('value', 'Playwright: Review Resource')->first();
        $reviewResource = $legacyReviewTitle?->resource;

        if (! $reviewResource) {
            $reviewResource = Resource::query()->where('doi', self::PLAYWRIGHT_REVIEW_RESOURCE_DOI)->first();
        }

        if (! $reviewResource) {
            $reviewResource = Resource::factory()->create(array_merge($resourceAttributes, ['doi' => self::PLAYWRIGHT_REVIEW_RESOURCE_DOI]));

            ResourceCreator::factory()->create([
                'resource_id' => $reviewResource->id,
                'position' => 1,
            ]);
        }

        // Ensure review resource has the canonical DOI.
        if ($reviewResource->doi !== self::PLAYWRIGHT_REVIEW_RESOURCE_DOI) {
            $reviewResource->doi = self::PLAYWRIGHT_REVIEW_RESOURCE_DOI;
            $reviewResource->save();
        }

        // Ensure title exists and does not contain "Review" (case-insensitive) to avoid
        // Playwright :text("Review") selectors matching the resource title instead of the
        // status badge. The "Playwright:" prefix is intentional and used consistently across
        // all test fixtures for easy identification, but won't collide with status badge selectors.
        $reviewTitle = Title::query()->where('resource_id', $reviewResource->id)->first();
        if ($reviewTitle === null) {
            Title::factory()->create([
                'resource_id' => $reviewResource->id,
                'value' => 'Playwright: QA Resource',
            ]);
        } elseif (stripos($reviewTitle->value, 'review') !== false) {
            $reviewTitle->value = 'Playwright: QA Resource';
            $reviewTitle->save();
        }

        $reviewLandingPage = LandingPage::query()->updateOrCreate(
            ['slug' => 'playwright-review'],
            [
                'resource_id' => $reviewResource->id,
                'template' => 'default_gfz',
                'is_published' => false,
                'published_at' => null,
                // Ensure a preview URL exists (used by UI for review status).
                'preview_token' => Str::random(64),
            ]
        );

        // 3) Curation resource WITHOUT landing page (for "landing page required" negative cases)
        $noLandingPageTitle = Title::query()->where('value', 'Playwright: Curation Resource (no landing page)')->first();
        if (! $noLandingPageTitle) {
            $resource = Resource::factory()->create(array_merge($resourceAttributes, ['doi' => null]));
            Title::factory()->create([
                'resource_id' => $resource->id,
                'value' => 'Playwright: Curation Resource (no landing page)',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'position' => 1,
            ]);
        }

        // 4) Curation resource WITH landing page but WITHOUT DOI (used for DOI registration modal tests)
        // Keep this last so it appears first in the /resources list (default sort: updated_at desc).
        $curationLandingPage = LandingPage::query()->where('slug', 'playwright-curation')->first();
        if (! $curationLandingPage) {
            $curationResource = Resource::factory()->create(array_merge($resourceAttributes, ['doi' => null]));

            Title::factory()->create([
                'resource_id' => $curationResource->id,
                'value' => 'Playwright: Curation Resource (no DOI)',
            ]);

            ResourceCreator::factory()->create([
                'resource_id' => $curationResource->id,
                'position' => 1,
            ]);

            LandingPage::create([
                'resource_id' => $curationResource->id,
                'slug' => 'playwright-curation',
                'template' => 'default_gfz',
                'is_published' => true,
                'published_at' => now(),
                // Enable preview functionality for this curation fixture.
                // Used by E2E tests to verify landing page preview URLs work correctly.
                'preview_token' => Str::random(64),
            ]);
        }

        $this->command->info('Playwright E2E resources ensured successfully!');
    }
}
