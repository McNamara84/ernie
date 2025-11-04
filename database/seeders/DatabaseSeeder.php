<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $testUser = null;
        if (! User::where('email', 'test@example.com')->exists()) {
            $testUser = User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        } else {
            $testUser = User::where('email', 'test@example.com')->first();
        }

        $this->call([
            RoleSeeder::class,
            ResourceTypeSeeder::class,
            TitleTypeSeeder::class,
            LicenseSeeder::class,
            LanguageSeeder::class,
        ]);

        // Create test resources for Playwright E2E tests
        // These are minimal resources to ensure the UI has data to display
        if (app()->environment('testing', 'local')) {
            $this->createTestResources($testUser);
        }
    }

    /**
     * Create minimal test resources for E2E testing
     */
    private function createTestResources(User $user): void
    {
        // Only create if no resources exist
        if (\App\Models\Resource::count() > 0) {
            return;
        }

        // Create resources with different states for comprehensive testing
        
        // 1. Resource in Curation status (no DOI, no landing page)
        \App\Models\Resource::factory()
            ->create([
                'doi' => null, // Critical: no DOI means Curation status
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

        // 2. Resource with landing page but no DOI (ready for DOI registration)
        $resourceWithLandingPage = \App\Models\Resource::factory()
            ->create([
                'doi' => null,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
        
        // Create landing page for this resource
        \App\Models\LandingPage::create([
            'resource_id' => $resourceWithLandingPage->id,
            'template' => \App\Models\LandingPage::TEMPLATE_DEFAULT_GFZ,
            'status' => \App\Models\LandingPage::STATUS_PUBLISHED,
            'preview_token' => \Illuminate\Support\Str::random(64),
            'published_at' => now(),
        ]);

        // 3. Resource with DOI and landing page (Published/Review status)
        $publishedResource = \App\Models\Resource::factory()
            ->create([
                'doi' => '10.83279/test-playwright',
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
        
        \App\Models\LandingPage::create([
            'resource_id' => $publishedResource->id,
            'template' => \App\Models\LandingPage::TEMPLATE_DEFAULT_GFZ,
            'status' => \App\Models\LandingPage::STATUS_PUBLISHED,
            'preview_token' => \Illuminate\Support\Str::random(64),
            'published_at' => now(),
        ]);

        $this->command->info('Created 3 test resources for E2E testing (Curation, Ready for DOI, Published)');
    }
}
