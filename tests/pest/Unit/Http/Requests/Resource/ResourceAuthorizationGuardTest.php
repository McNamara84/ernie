<?php

declare(strict_types=1);

use App\Http\Requests\Resource\DestroyResourceRequest;
use App\Http\Requests\Resource\ExportResourceRequest;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;

uses(RefreshDatabase::class);

/**
 * Build a request whose `resource` route parameter resolves to the given value.
 * Mirrors how Laravel exposes route bindings via `$this->route('resource')`
 * inside FormRequest::authorize().
 *
 * @template TRequest of \Illuminate\Foundation\Http\FormRequest
 *
 * @param  class-string<TRequest>  $class
 * @return TRequest
 */
function makeRequestWithRouteResource(string $class, mixed $routeResource, ?User $user): FormRequest
{
    /** @var FormRequest $request */
    $request = $class::create('/test', 'DELETE');

    $route = new Route(['DELETE'], '/test/{resource}', []);
    $route->bind($request);
    $route->setParameter('resource', $routeResource);

    $request->setRouteResolver(fn () => $route);

    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}

describe('DestroyResourceRequest::authorize() route-binding guard', function () {
    it('returns false when the route parameter is not a Resource (Issue: PR #679 review)', function () {
        $user = User::factory()->create();

        // Simulates a misconfigured route or failed binding where the parameter
        // arrives as a raw scalar instead of a Resource instance. Without the
        // `instanceof` guard, ResourcePolicy::delete(User, Resource) would
        // throw a TypeError before Laravel can return a 403.
        $req = makeRequestWithRouteResource(DestroyResourceRequest::class, '123', $user);

        expect($req->authorize())->toBeFalse();
    });

    it('returns false when there is no authenticated user', function () {
        $resource = Resource::factory()->create();
        $req = makeRequestWithRouteResource(DestroyResourceRequest::class, $resource, null);

        expect($req->authorize())->toBeFalse();
    });
});

describe('ExportResourceRequest::authorize() route-binding guard', function () {
    it('returns false when the route parameter is not a Resource (Issue: PR #679 review)', function () {
        $user = User::factory()->create();

        // Without the guard, ResourcePolicy::view(User, Resource) would throw
        // a TypeError when the route resolves to a scalar.
        $req = makeRequestWithRouteResource(ExportResourceRequest::class, 'not-a-model', $user);

        expect($req->authorize())->toBeFalse();
    });

    it('returns false when the route parameter is null', function () {
        $user = User::factory()->create();
        $req = makeRequestWithRouteResource(ExportResourceRequest::class, null, $user);

        expect($req->authorize())->toBeFalse();
    });

    it('returns false when there is no authenticated user', function () {
        $resource = Resource::factory()->create();
        $req = makeRequestWithRouteResource(ExportResourceRequest::class, $resource, null);

        expect($req->authorize())->toBeFalse();
    });
});
