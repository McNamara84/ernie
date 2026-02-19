<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTestEnvironment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

covers(EnsureTestEnvironment::class);

describe('EnsureTestEnvironment middleware', function (): void {
    it('allows requests in testing environment', function (): void {
        config(['app.env' => 'testing']);
        $middleware = new EnsureTestEnvironment();
        $request = Request::create('/test-route');
        $called = false;

        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;

            return new \Illuminate\Http\Response('OK');
        });

        expect($called)->toBeTrue();
        expect($response->getStatusCode())->toBe(200);
    });

    it('allows requests in local environment', function (): void {
        config(['app.env' => 'local']);
        $middleware = new EnsureTestEnvironment();
        $request = Request::create('/test-route');
        $called = false;

        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;

            return new \Illuminate\Http\Response('OK');
        });

        expect($called)->toBeTrue();
    });

    it('returns 404 in production environment', function (): void {
        config(['app.env' => 'production']);
        $middleware = new EnsureTestEnvironment();
        $request = Request::create('/test-route');

        $middleware->handle($request, function () {
            return new \Illuminate\Http\Response('OK');
        });
    })->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    it('returns 404 in staging environment', function (): void {
        config(['app.env' => 'staging']);
        $middleware = new EnsureTestEnvironment();
        $request = Request::create('/test-route');

        $middleware->handle($request, function () {
            return new \Illuminate\Http\Response('OK');
        });
    })->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
