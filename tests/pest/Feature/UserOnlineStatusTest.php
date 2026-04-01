<?php

declare(strict_types=1);

use App\Models\User;

describe('User Model - isOnline', function (): void {
    it('returns true when last_seen_at is within the configured window', function (): void {
        /** @var int $windowMinutes */
        $windowMinutes = config('users.online_window_minutes');
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes($windowMinutes - 1)]);

        expect($user->isOnline())->toBeTrue();
    });

    it('returns true when last_seen_at is just now', function (): void {
        $user = User::factory()->create(['last_seen_at' => now()]);

        expect($user->isOnline())->toBeTrue();
    });

    it('returns false when last_seen_at is older than the configured window', function (): void {
        /** @var int $windowMinutes */
        $windowMinutes = config('users.online_window_minutes');
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes($windowMinutes + 1)]);

        expect($user->isOnline())->toBeFalse();
    });

    it('returns false when last_seen_at is null', function (): void {
        $user = User::factory()->create(['last_seen_at' => null]);

        expect($user->isOnline())->toBeFalse();
    });

    it('returns false when last_seen_at is exactly at the configured window boundary', function (): void {
        /** @var int $windowMinutes */
        $windowMinutes = config('users.online_window_minutes');
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes($windowMinutes)]);

        expect($user->isOnline())->toBeFalse();
    });
});
