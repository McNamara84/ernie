<?php

declare(strict_types=1);

use App\Http\Middleware\TrackLastSeenMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

describe('TrackLastSeenMiddleware', function (): void {
    it('updates last_seen_at for authenticated users', function (): void {
        $user = User::factory()->create(['last_seen_at' => null]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at)->not->toBeNull();
    });

    it('does not update last_seen_at if within throttle window', function (): void {
        $recentTime = now()->subMinutes(2);
        $user = User::factory()->create(['last_seen_at' => $recentTime]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at->toDateTimeString())
            ->toBe($recentTime->toDateTimeString());
    });

    it('updates last_seen_at if older than throttle window', function (): void {
        $oldTime = now()->subMinutes(10);
        $user = User::factory()->create(['last_seen_at' => $oldTime]);

        $this->actingAs($user)
            ->get('/dashboard');

        $user->refresh();
        expect($user->last_seen_at->isAfter($oldTime))->toBeTrue();
    });

    it('does not throw for guest users', function (): void {
        $middleware = new TrackLastSeenMiddleware;

        $request = Request::create('/test', 'GET');
        $response = $middleware->handle($request, fn () => new Response('OK'));

        expect($response->getStatusCode())->toBe(200);
    });
});
