<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetUrlRoot
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // In production, always set the correct URL root
        if (app()->environment('production')) {
            $appUrl = config('app.url');
            if ($appUrl) {
                // Set the root URL (includes scheme and path prefix)
                URL::forceRootUrl($appUrl);

                // Mark the request as secure if using HTTPS
                // Note: Do NOT call URL::forceScheme('https') here!
                // The scheme is already included in APP_URL.
                // Calling forceScheme after forceRootUrl causes double-protocol URLs
                if (str_starts_with($appUrl, 'https://')) {
                    $request->server->set('HTTPS', 'on');
                }
            }
        }

        return $next($request);
    }
}
