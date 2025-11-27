<?php
/**
 * Script to create test user for Playwright tests.
 * Run with: php artisan tinker < docker/create-test-user.php
 */

use App\Models\User;
use App\Enums\UserRole;

$user = User::firstOrCreate(
    ['email' => 'test@example.com'],
    [
        'name' => 'Test User',
        'password' => bcrypt('password'),
        'role' => UserRole::ADMIN,
        'email_verified_at' => now()
    ]
);

echo "Test user created/exists: " . $user->email . "\n";
exit(0);
