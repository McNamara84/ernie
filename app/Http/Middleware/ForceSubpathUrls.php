<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceSubpathUrls
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force the URL generator to use the correct base path
        if ($request->hasHeader('X-Forwarded-Prefix')) {
            $prefix = $request->header('X-Forwarded-Prefix');
            URL::forceRootUrl(config('app.url'));
        } else {
            // Fallback: detect from APP_URL if it contains a path
            $appUrl = config('app.url');
            if ($appUrl && parse_url($appUrl, PHP_URL_PATH) !== '/') {
                URL::forceRootUrl($appUrl);
            }
        }

        return $next($request);
    }
}