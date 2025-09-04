<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('add-user {name} {email} {password}', function (string $name, string $email, string $password) {
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    $this->info("User {$user->email} created.");
})->purpose('Add a new user to the database');
