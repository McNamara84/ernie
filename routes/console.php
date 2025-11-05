<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('add-user {name} {email} {password}', function (string $name, string $email, string $password) {
    // Check if this is the first user
    $isFirstUser = User::count() === 0;

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        // First user automatically becomes admin
        'role' => $isFirstUser ? \App\Enums\UserRole::ADMIN : \App\Enums\UserRole::BEGINNER,
    ]);

    if ($isFirstUser) {
        $this->info("User {$user->email} created as ADMIN (first user).");
    } else {
        $this->info("User {$user->email} created as BEGINNER.");
    }
})->purpose('Add a new user to the database');
