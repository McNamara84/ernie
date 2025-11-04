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

        // Create 3 resources with different statuses for testing
        \App\Models\Resource::factory()
            ->count(3)
            ->create([
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

        $this->command->info('Created 3 test resources for E2E testing');
    }
}
