<?php

declare(strict_types=1);

use App\Models\User;

describe('User Model - isOnline', function (): void {
    it('returns true when last_seen_at is within 5 minutes', function (): void {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(2)]);

        expect($user->isOnline())->toBeTrue();
    });

    it('returns true when last_seen_at is just now', function (): void {
        $user = User::factory()->create(['last_seen_at' => now()]);

        expect($user->isOnline())->toBeTrue();
    });

    it('returns false when last_seen_at is older than 5 minutes', function (): void {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(10)]);

        expect($user->isOnline())->toBeFalse();
    });

    it('returns false when last_seen_at is null', function (): void {
        $user = User::factory()->create(['last_seen_at' => null]);

        expect($user->isOnline())->toBeFalse();
    });

    it('returns false when last_seen_at is exactly 5 minutes ago', function (): void {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

        expect($user->isOnline())->toBeFalse();
    });
});
