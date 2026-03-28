<?php

use App\Jobs\DiscoverRelationsJob;
use App\Models\User;
use App\Services\VocabularyCacheService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

// Extend vocabulary cache TTLs every 12 hours without re-fetching data
Schedule::call(function () {
    app(VocabularyCacheService::class)->touchAllVocabularyCaches();
})->twiceDaily(1, 13)
    ->name('touch-vocabulary-caches')
    ->withoutOverlapping();

// Discover new related works every Sunday at 02:00 UTC
Schedule::call(function () {
    DiscoverRelationsJob::dispatch(Str::uuid()->toString());
})->weeklyOn(0, '02:00')
    ->name('discover-relations')
    ->withoutOverlapping();
