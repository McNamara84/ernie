<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLastSeenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $this->updateLastSeen($user);
        }

        return $next($request);
    }

    /**
     * Update the user's last_seen_at timestamp if throttle threshold has passed.
     */
    private function updateLastSeen(User $user): void
    {
        /** @var int $windowMinutes */
        $windowMinutes = max(1, (int) config('users.online_window_minutes'));

        if ($user->last_seen_at !== null && $user->last_seen_at->isAfter(now()->subMinutes($windowMinutes))) {
            return;
        }

        User::withoutTimestamps(function () use ($user): void {
            $user->last_seen_at = now();
            $user->saveQuietly();
        });
    }
}
