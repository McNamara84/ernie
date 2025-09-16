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
        // Force die Root-URL mit dem /ernie Präfix
        URL::forceRootUrl(config('app.url'));
        
        // Wichtig für HTTPS
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
        
        return $next($request);
    }
}
