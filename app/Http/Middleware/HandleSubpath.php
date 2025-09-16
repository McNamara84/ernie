<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class HandleSubpath
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the app URL and extract the path
        $appUrl = config('app.url');
        if ($appUrl) {
            $path = parse_url($appUrl, PHP_URL_PATH);
            if ($path && $path !== '/') {
                // Force Laravel's URL generator to use the correct base URL
                URL::forceRootUrl($appUrl);
                
                // Set the base path for the request
                $request->server->set('SCRIPT_NAME', $path . '/index.php');
                
                // Ensure session cookie path is set correctly
                config(['session.path' => $path]);
            }
        }
        
        return $next($request);
    }
}
