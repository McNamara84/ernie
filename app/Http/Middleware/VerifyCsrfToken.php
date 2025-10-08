<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'dashboard/upload-xml',
    ];

    /**
     * Determine if the session and input CSRF tokens match.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function tokensMatch($request)
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($token) &&
               hash_equals($request->session()->token(), $token);
    }

    /**
     * Add the CSRF token to the response cookies.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addCookieToResponse($request, $response)
    {
        $config = config('session');

        if ($response instanceof \Illuminate\Http\Response ||
            $response instanceof \Illuminate\Http\JsonResponse) {
            $response->withCookie(
                cookie(
                    'XSRF-TOKEN',
                    $request->session()->token(),
                    $config['lifetime'],
                    $config['path'],
                    $config['domain'],
                    $config['secure'],
                    false,
                    false,
                    $config['same_site'] ?? null
                )
            );
        }

        return $response;
    }
}