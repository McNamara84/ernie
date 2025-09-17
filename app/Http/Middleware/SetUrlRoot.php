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
        $prefix = $request->header('X-Forwarded-Prefix');
        
        if ($prefix) {
            $prefix = '/' . trim($prefix, '/');
            URL::forceRootUrl($request->getSchemeAndHttpHost() . $prefix);
        }

        return $next($request);
    }
}
