<?php

declare(strict_types=1);

use App\Models\User;

describe('UserController - Last Seen Data', function (): void {
    beforeEach(function (): void {
        config()->set('users.online_window_minutes', 5);
    });

    it('includes last_seen_at and is_online in user data for admin', function (): void {
        $admin = User::factory()->admin()->create(['last_seen_at' => now()]);
        $onlineUser = User::factory()->create(['last_seen_at' => now()->subMinutes(2)]);
        $offlineUser = User::factory()->create(['last_seen_at' => now()->subHours(1)]);
        $neverSeenUser = User::factory()->create(['last_seen_at' => null]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Index')
                ->has('users', 4)
                ->where('users.1.is_online', true)
                ->where('users.1.last_seen_at', fn ($value) => $value !== null)
                ->where('users.2.is_online', false)
                ->where('users.2.last_seen_at', fn ($value) => $value !== null)
                ->where('users.3.is_online', false)
                ->where('users.3.last_seen_at', null));
    });

    it('includes last_seen_at and is_online in user data for group leader', function (): void {
        $groupLeader = User::factory()->groupLeader()->create(['last_seen_at' => now()]);
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(30)]);

        $this->actingAs($groupLeader)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Index')
                ->has('users', 2)
                ->where('users.0.is_online', true)
                ->where('users.1.is_online', false));
    });

    it('shows is_online as true for recently active user', function (): void {
        $admin = User::factory()->admin()->create(['last_seen_at' => now()]);
        $recentUser = User::factory()->create(['last_seen_at' => now()->subMinutes(1)]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.1.is_online', true));
    });

    it('shows is_online as false for user who was active long ago', function (): void {
        $admin = User::factory()->admin()->create(['last_seen_at' => now()]);
        $oldUser = User::factory()->create(['last_seen_at' => now()->subDays(3)]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.1.is_online', false)
                ->where('users.1.last_seen_at', fn ($value) => $value !== null));
    });
});
