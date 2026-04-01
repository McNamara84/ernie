<?php

declare(strict_types=1);

use App\Http\Middleware\TrackLastSeenMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

describe('TrackLastSeenMiddleware', function (): void {
    beforeEach(function (): void {
        config()->set('users.online_window_minutes', 5);
    });

    it('updates last_seen_at for authenticated users', function (): void {
        $user = User::factory()->create(['last_seen_at' => null]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at)->not->toBeNull();
    });

    it('does not update last_seen_at if within throttle window', function (): void {
        /** @var int $windowMinutes */
        $windowMinutes = config('users.online_window_minutes');
        $recentTime = now()->subMinutes($windowMinutes - 1);
        $user = User::factory()->create(['last_seen_at' => $recentTime]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at->toDateTimeString())
            ->toBe($recentTime->toDateTimeString());
    });

    it('updates last_seen_at if older than throttle window', function (): void {
        /** @var int $windowMinutes */
        $windowMinutes = config('users.online_window_minutes');
        $oldTime = now()->subMinutes($windowMinutes + 1);
        $user = User::factory()->create(['last_seen_at' => $oldTime]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at->isAfter($oldTime))->toBeTrue();
    });

    it('does not throw for guest users', function (): void {
        $middleware = new TrackLastSeenMiddleware;

        $request = Request::create('/test', 'GET');
        $response = $middleware->handle($request, fn (Request $req) => new Response('OK'));

        expect($response->getStatusCode())->toBe(200);
    });

    it('does not modify updated_at timestamp', function (): void {
        $originalUpdatedAt = now()->subDays(5);
        $user = User::factory()->create([
            'last_seen_at' => null,
            'updated_at' => $originalUpdatedAt,
        ]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at)->not->toBeNull();
        expect($user->updated_at->toDateTimeString())
            ->toBe($originalUpdatedAt->toDateTimeString());
    });
});
