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
        // Seed essential data only (no test users or resources in development)
        $this->call([
            RoleSeeder::class,
            ResourceTypeSeeder::class,
            TitleTypeSeeder::class,
            LicenseSeeder::class,
            LanguageSeeder::class,
        ]);

        // Only create test data in testing environment (for automated tests)
        if (app()->environment('testing')) {
            $this->createTestDataForAutomatedTests();
        }
    }

    /**
     * Create test users with different roles for automated testing only
     */
    private function createTestDataForAutomatedTests(): void
    {
        // Create test user for automated tests
        $testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Admin user
        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        // Group Leader user
        User::factory()->groupLeader()->create([
            'name' => 'Group Leader',
            'email' => 'groupleader@example.com',
        ]);

        // Curator user
        User::factory()->curator()->create([
            'name' => 'Curator User',
            'email' => 'curator@example.com',
        ]);

        // Beginner user
        User::factory()->create([
            'name' => 'Beginner User',
            'email' => 'beginner@example.com',
        ]);

        // Deactivated user
        User::factory()->deactivated()->create([
            'name' => 'Deactivated User',
            'email' => 'deactivated@example.com',
        ]);

        $this->createTestResources($testUser);
    }

    /**
     * Create minimal test resources for E2E testing
     */
    private function createTestResources(User $user): void
    {
        // Create resources with different states for comprehensive testing
        
        // 1. Resource in Curation status (no DOI) WITH landing page (ready for DOI registration)
        $curationResource = \App\Models\Resource::factory()
            ->create([
                'doi' => null, // Critical: no DOI means Curation status
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
        
        \App\Models\LandingPage::create([
            'resource_id' => $curationResource->id,
            'template' => \App\Models\LandingPage::TEMPLATE_DEFAULT_GFZ,
            'status' => \App\Models\LandingPage::STATUS_PUBLISHED,
            'preview_token' => \Illuminate\Support\Str::random(64),
            'published_at' => now(),
        ]);

        // 2. Second Curation resource WITH landing page (for testing multiple resources)
        $curationResource2 = \App\Models\Resource::factory()
            ->create([
                'doi' => null,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
        
        \App\Models\LandingPage::create([
            'resource_id' => $curationResource2->id,
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

        // 4. Curation resource WITHOUT landing page (for testing the landing page required error in DOI registration)
        \App\Models\Resource::factory()
            ->create([
                'doi' => null,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);
    }
}
