<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\URL;

class SetUrlRoot
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // First, try to use X-Forwarded-Prefix header if available
            $prefix = $request->header('X-Forwarded-Prefix');
            
            if ($prefix) {
                $prefix = '/' . trim($prefix, '/');
                URL::forceRootUrl($request->getSchemeAndHttpHost() . $prefix);
            } else {
                // For production behind Traefik with stripprefix, use configured URLs
                $this->configureUrlsForProduction($request);
            }
        } catch (\Exception $e) {
            // Log error but continue processing
            if (function_exists('logger')) {
                logger()->error('SetUrlRoot middleware error: ' . $e->getMessage());
            }
        }

        return $next($request);
    }

    /**
     * Configure URLs for production deployment behind Traefik
     */
    private function configureUrlsForProduction(Request $request): void
    {
        try {
            if (app()->environment('production')) {
                // Use the configured app URL which includes the path prefix
                $appUrl = config('app.url');
                if ($appUrl) {
                    URL::forceRootUrl($appUrl);
                    
                    // Force HTTPS if the app URL uses HTTPS
                    if (str_starts_with($appUrl, 'https://')) {
                        URL::forceScheme('https');
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently continue
        }
    }
}
