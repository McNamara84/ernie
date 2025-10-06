<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidElmoApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('services.elmo.api_key');

        if ($configuredKey === null || $configuredKey === '') {
            return $next($request);
        }

        $providedKey = $this->extractApiKey($request);

        if (!is_string($providedKey) || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
            return new JsonResponse([
                'message' => 'Invalid API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $headerKey = $request->header('X-API-Key');

        if (is_string($headerKey) && $headerKey !== '') {
            return $headerKey;
        }

        $bearerToken = $request->bearerToken();
        if (is_string($bearerToken) && $bearerToken !== '') {
            return $bearerToken;
        }

        $queryKey = $request->query('api_key');

        if (is_string($queryKey) && $queryKey !== '') {
            return $queryKey;
        }

        if (is_array($queryKey) && $queryKey !== []) {
            $value = reset($queryKey);

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
