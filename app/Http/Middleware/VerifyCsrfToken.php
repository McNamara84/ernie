<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    /**
     * Add the CSRF token to the response cookies.
     *
     * This override ensures the XSRF-TOKEN cookie is properly set for ALL response types,
     * including Inertia responses, not just Illuminate\Http\Response and JsonResponse.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addCookieToResponse($request, $response)
    {
        $config = config('session');

        // Convert Responsable objects to Response (like Inertia\Response)
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        // Use the Symfony response headers directly to ensure cookie is set for ALL response types
        $response->headers->setCookie(
            new Cookie(
                'XSRF-TOKEN',
                $request->session()->token(),
                $this->availableAt(60 * $config['lifetime']),
                $config['path'],
                $config['domain'],
                $config['secure'],
                false,
                false,
                $config['same_site'] ?? null,
                $config['partitioned'] ?? false
            )
        );

        return $response;
    }
}
