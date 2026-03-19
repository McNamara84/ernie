<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetUrlRoot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| HandleAppearance Middleware
|--------------------------------------------------------------------------
*/

describe('HandleAppearance', function () {
    it('shares appearance cookie value with views', function () {
        $middleware = new HandleAppearance;
        $request = Request::create('/test');
        $request->cookies->set('appearance', 'dark');

        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $shared = View::getShared();
        expect($shared['appearance'])->toBe('dark');
    });

    it('defaults to system when no cookie is present', function () {
        $middleware = new HandleAppearance;
        $request = Request::create('/test');

        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $shared = View::getShared();
        expect($shared['appearance'])->toBe('system');
    });

    it('shares light appearance', function () {
        $middleware = new HandleAppearance;
        $request = Request::create('/test');
        $request->cookies->set('appearance', 'light');

        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $shared = View::getShared();
        expect($shared['appearance'])->toBe('light');
    });

    it('passes the request through to next middleware', function () {
        $middleware = new HandleAppearance;
        $request = Request::create('/test');

        $response = $middleware->handle($request, fn ($req) => response('passed'));

        expect($response->getContent())->toBe('passed');
    });
});

/*
|--------------------------------------------------------------------------
| SetUrlRoot Middleware
|--------------------------------------------------------------------------
*/

describe('SetUrlRoot', function () {
    afterEach(function () {
        // Reset global URL generator state to prevent cross-test pollution
        URL::forceRootUrl('');
        URL::forceScheme('http');
        app()->detectEnvironment(fn () => 'testing');
    });

    it('does not change URL root in non-production environment', function () {
        app()->detectEnvironment(fn () => 'testing');

        $middleware = new SetUrlRoot;
        $request = Request::create('http://localhost/test');

        $middleware->handle($request, fn ($req) => response('ok'));

        // In non-production, the URL root should not be forced
        expect(URL::to('/'))->toContain('localhost');
    });

    it('passes the request through', function () {
        $middleware = new SetUrlRoot;
        $request = Request::create('/test');

        $response = $middleware->handle($request, fn ($req) => response('ok'));

        expect($response->getContent())->toBe('ok');
    });

    it('forces root URL in production when app URL is configured', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://ernie.example.org']);

        $middleware = new SetUrlRoot;
        $request = Request::create('http://localhost/test');

        $middleware->handle($request, fn ($req) => response('ok'));

        expect(URL::to('/'))->toBe('https://ernie.example.org');
        expect($request->server->get('HTTPS'))->toBe('on');
    });

    it('forces HTTPS scheme in production for https app URL', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://secure.example.org']);

        $middleware = new SetUrlRoot;
        $request = Request::create('http://localhost/test');

        $middleware->handle($request, fn ($req) => response('ok'));

        expect(URL::to('/path'))->toStartWith('https://');
    });

    it('does not force HTTPS for http app URL in production', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.url' => 'http://ernie.local']);

        $middleware = new SetUrlRoot;
        $request = Request::create('http://localhost/test');

        $middleware->handle($request, fn ($req) => response('ok'));

        expect(URL::to('/'))->toBe('http://ernie.local');
        expect($request->server->get('HTTPS'))->not->toBe('on');
    });
});

/*
|--------------------------------------------------------------------------
| HandleInertiaRequests Middleware
|--------------------------------------------------------------------------
*/

describe('HandleInertiaRequests', function () {
    it('shares app name', function () {
        config(['app.name' => 'ERNIE']);

        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared['name'])->toBe('ERNIE');
    });

    it('shares quote with message and author', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared)->toHaveKey('quote');
        expect($shared['quote'])->toHaveKeys(['message', 'author']);
        expect($shared['quote']['message'])->toBeString();
        expect($shared['quote']['author'])->toBeString();
    });

    it('shares null auth when no user is logged in', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared['auth']['user'])->toBeNull();
    });

    it('shares authenticated user data with permissions', function () {
        $user = User::factory()->create();
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);

        $shared = $middleware->share($request);

        expect($shared['auth']['user'])->not->toBeNull();
        expect($shared['auth']['user'])->toHaveKeys([
            'role',
            'role_label',
            'can_manage_users',
            'can_register_production_doi',
            'can_delete_logs',
            'can_access_logs',
            'can_access_old_datasets',
            'can_access_statistics',
            'can_access_users',
            'can_access_editor_settings',
            'can_manage_landing_pages',
        ]);
    });

    it('shares sidebarOpen as true when no cookie is set', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared['sidebarOpen'])->toBeTrue();
    });

    it('shares fontSizePreference as regular when no user', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared['fontSizePreference'])->toBe('regular');
    });

    it('shares fontSizePreference from authenticated user', function () {
        $user = User::factory()->create(['font_size_preference' => 'large']);
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);

        $shared = $middleware->share($request);

        expect($shared['fontSizePreference'])->toBe('large');
    });

    it('shares appUrl and baseUrl', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('http://localhost/test');

        $shared = $middleware->share($request);

        expect($shared)->toHaveKeys(['appUrl', 'baseUrl']);
        expect($shared['appUrl'])->toBeString();
        expect($shared['baseUrl'])->toBeString();
    });

    it('shares pathPrefix', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared)->toHaveKey('pathPrefix');
        expect($shared['pathPrefix'])->toBeString();
    });

    it('returns empty pathPrefix in non-production', function () {
        app()->detectEnvironment(fn () => 'testing');
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $shared = $middleware->share($request);

        expect($shared['pathPrefix'])->toBe('');
    });

    it('returns production base URL from config', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://ernie.gfz.example.org']);

        $middleware = new HandleInertiaRequests;
        $request = Request::create('http://localhost/test');

        $shared = $middleware->share($request);

        expect($shared['baseUrl'])->toBe('https://ernie.gfz.example.org');

        // Reset
        app()->detectEnvironment(fn () => 'testing');
    });

    it('returns path prefix from production app URL', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://example.org/ernie']);

        $middleware = new HandleInertiaRequests;
        $request = Request::create('http://localhost/ernie/test');

        $shared = $middleware->share($request);

        expect($shared['pathPrefix'])->toBe('/ernie');

        // Reset
        app()->detectEnvironment(fn () => 'testing');
    });

    it('returns version from parent', function () {
        $middleware = new HandleInertiaRequests;
        $request = Request::create('/test');

        $version = $middleware->version($request);

        // version returns nullable string
        expect($version)->toBeString()->or->toBeNull();
    });
});
