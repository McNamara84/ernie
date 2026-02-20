<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureValidApiKey;
use App\Http\Middleware\EnsureValidErnieApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

covers(EnsureValidApiKey::class, EnsureValidErnieApiKey::class);

describe('EnsureValidErnieApiKey middleware', function (): void {
    beforeEach(function (): void {
        $this->middleware = new EnsureValidErnieApiKey();
        $this->next = fn () => new \Illuminate\Http\Response('OK');
    });

    it('rejects requests when API key is not configured', function (): void {
        config(['services.ernie.api_key' => null]);
        $request = Request::create('/api/test');

        $response = $this->middleware->handle($request, $this->next);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
        expect($response->getData(true)['message'])->toBe('API key not configured.');
    });

    it('rejects requests when API key is empty string', function (): void {
        config(['services.ernie.api_key' => '']);
        $request = Request::create('/api/test');

        $response = $this->middleware->handle($request, $this->next);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
        expect($response->getData(true)['message'])->toBe('API key not configured.');
    });

    it('rejects requests with no API key provided', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');

        $response = $this->middleware->handle($request, $this->next);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
        expect($response->getData(true)['message'])->toBe('Invalid API key.');
    });

    it('rejects requests with wrong API key via X-API-Key header', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');
        $request->headers->set('X-API-Key', 'wrong-key');

        $response = $this->middleware->handle($request, $this->next);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    it('accepts requests with correct X-API-Key header', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');
        $request->headers->set('X-API-Key', 'valid-secret-key');

        $response = $this->middleware->handle($request, $this->next);

        expect($response->getStatusCode())->toBe(200);
    });

    it('accepts requests with correct Bearer token', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');
        $request->headers->set('Authorization', 'Bearer valid-secret-key');

        $response = $this->middleware->handle($request, $this->next);

        expect($response->getStatusCode())->toBe(200);
    });

    it('rejects requests with wrong Bearer token', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');
        $request->headers->set('Authorization', 'Bearer wrong-key');

        $response = $this->middleware->handle($request, $this->next);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    it('prefers X-API-Key header over Bearer token', function (): void {
        config(['services.ernie.api_key' => 'valid-secret-key']);
        $request = Request::create('/api/test');
        $request->headers->set('X-API-Key', 'valid-secret-key');
        $request->headers->set('Authorization', 'Bearer wrong-key');

        // X-API-Key is checked first, so this should succeed
        $response = $this->middleware->handle($request, $this->next);

        expect($response->getStatusCode())->toBe(200);
    });
});

describe('EnsureValidErnieApiKey service name', function (): void {
    it('uses ernie as service name', function (): void {
        $middleware = new EnsureValidErnieApiKey();
        $reflection = new ReflectionMethod($middleware, 'serviceName');

        expect($reflection->invoke($middleware))->toBe('ernie');
    });
});
