<?php

namespace App\Http\Middleware;

use App\Support\UrlNormalizer;
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
            $appUrl = UrlNormalizer::normalizeAppUrl(config('app.url'));
            if ($appUrl !== null) {
                URL::forceRootUrl($appUrl);

                // Force HTTPS scheme if the app URL uses HTTPS
                if (str_starts_with($appUrl, 'https://')) {
                    URL::forceScheme('https');
                    // Also mark the request as secure
                    $request->server->set('HTTPS', 'on');
                }
            }
        }

        return $next($request);
    }
}
