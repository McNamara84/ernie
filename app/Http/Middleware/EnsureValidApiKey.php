<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class EnsureValidApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('services.'.$this->serviceName().'.api_key');

        if (! is_string($configuredKey) || $configuredKey === '') {
            return $next($request);
        }

        $providedKey = $this->extractApiKey($request);

        if (! is_string($providedKey) || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return new JsonResponse([
                'message' => 'Invalid API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Resolve the service configuration key used for this middleware instance.
     */
    abstract protected function serviceName(): string;

    protected function extractApiKey(Request $request): ?string
    {
        // Only accept API keys via HTTP headers for security
        // Query parameters are NOT accepted as they can leak via:
        // - Server access logs
        // - Reverse proxy logs
        // - Referer headers to third-party domains
        $headerKey = $request->header('X-API-Key');

        if (is_string($headerKey) && $headerKey !== '') {
            return $headerKey;
        }

        $bearerToken = $request->bearerToken();
        if (is_string($bearerToken) && $bearerToken !== '') {
            return $bearerToken;
        }

        return null;
    }
}
