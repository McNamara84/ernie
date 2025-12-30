<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder for creating Playwright test users in Docker development environment.
 * 
 * Usage: php artisan db:seed --class=PlaywrightTestSeeder
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

    /**
     * Hashed password, computed once for efficiency.
     */
    private string $hashedPassword;

    public function run(): void
    {
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

        $this->seedMinimalResourceIfMissing($testUser);

        $this->command->info('Playwright test users created successfully!');
    }

    private function seedMinimalResourceIfMissing(User $testUser): void
    {
        if (Resource::query()->exists()) {
            $this->command->info('Resources already exist, skipping Playwright resource seed.');

            return;
        }

        $resourceAttributes = [];

        if (Schema::hasColumn('resources', 'created_by_user_id')) {
            $resourceAttributes['created_by_user_id'] = $testUser->id;
        }

        if (Schema::hasColumn('resources', 'updated_by_user_id')) {
            $resourceAttributes['updated_by_user_id'] = $testUser->id;
        }

        $resource = Resource::factory()
            ->withDoi('10.1234/playwright-preview')
            ->create($resourceAttributes);

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Playwright: Landing Page Preview Resource',
        ]);

        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'position' => 1,
        ]);

        $this->command->info('Playwright test resource created successfully!');
    }
}
