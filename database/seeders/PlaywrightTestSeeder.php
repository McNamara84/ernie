<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

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
    public function run(): void
    {
        // Create test user for Playwright tests
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create group leader
        User::firstOrCreate(
            ['email' => 'groupleader@example.com'],
            [
                'name' => 'Group Leader',
                'password' => bcrypt('password'),
                'role' => UserRole::GROUP_LEADER,
                'email_verified_at' => now(),
            ]
        );

        // Create curator
        User::firstOrCreate(
            ['email' => 'curator@example.com'],
            [
                'name' => 'Curator User',
                'password' => bcrypt('password'),
                'role' => UserRole::CURATOR,
                'email_verified_at' => now(),
            ]
        );

        // Create beginner
        User::firstOrCreate(
            ['email' => 'beginner@example.com'],
            [
                'name' => 'Beginner User',
                'password' => bcrypt('password'),
                'role' => UserRole::BEGINNER,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Playwright test users created successfully!');
    }
}
