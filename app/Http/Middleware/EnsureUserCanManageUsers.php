<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanManageUsers
{
    /**
     * Handle an incoming request.
     *
     * Ensures that only users with admin or group leader roles can access user management routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->canManageUsers()) {
            abort(403, 'You do not have permission to access user management.');
        }

        return $next($request);
    }
}
