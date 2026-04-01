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
     * The threshold in minutes before updating last_seen_at again.
     */
    private const int THROTTLE_MINUTES = 5;

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
        if ($user->last_seen_at !== null && $user->last_seen_at->diffInMinutes(now()) < self::THROTTLE_MINUTES) {
            return;
        }

        $user->last_seen_at = now();
        $user->saveQuietly();
    }
}
