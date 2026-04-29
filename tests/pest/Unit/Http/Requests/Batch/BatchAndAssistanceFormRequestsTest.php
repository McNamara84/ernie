<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Assistance\DeclineSuggestionRequest;
use App\Http\Requests\Batch\DestroyIgsnsRequest;
use App\Http\Requests\Batch\ExportResourcesRequest;
use App\Http\Requests\Batch\RegisterIgsnsRequest;
use App\Http\Requests\Batch\RegisterResourcesRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

covers(
    DeclineSuggestionRequest::class,
    DestroyIgsnsRequest::class,
    RegisterResourcesRequest::class,
    RegisterIgsnsRequest::class,
    ExportResourcesRequest::class,
);

it('DeclineSuggestionRequest authorizes only authenticated users', function (): void {
    $request = new DeclineSuggestionRequest;
    expect($request->authorize())->toBeFalse();

    $request->setUserResolver(fn () => User::factory()->create());
    expect($request->authorize())->toBeTrue();

    $rules = (new DeclineSuggestionRequest)->rules();
    expect(Validator::make([], $rules)->fails())->toBeFalse();
    expect(Validator::make(['reason' => str_repeat('x', 256)], $rules)->fails())->toBeTrue();
    expect(Validator::make(['reason' => 'too long?'], $rules)->fails())->toBeFalse();
});

it('DestroyIgsnsRequest authorizes only admins', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);

    $req = new DestroyIgsnsRequest;
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $curator);
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $admin);
    expect($req->authorize())->toBeTrue();

    $rules = $req->rules();
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => []], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => array_fill(0, 101, 1)], $rules)->fails())->toBeTrue();
});

it('RegisterResourcesRequest authorizes via register-production-doi gate', function (): void {
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);

    $req = new RegisterResourcesRequest;
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $beginner);
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $curator);
    expect($req->authorize())->toBeTrue();

    $rules = $req->rules();
    expect($rules)->toHaveKeys(['ids', 'ids.*', 'prefix']);
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => array_fill(0, 26, 1)], $rules)->fails())->toBeTrue();
});

it('RegisterIgsnsRequest authorizes via register-production-doi gate', function (): void {
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);

    $req = new RegisterIgsnsRequest;
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $beginner);
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $curator);
    expect($req->authorize())->toBeTrue();

    $rules = $req->rules();
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => array_fill(0, 26, 1)], $rules)->fails())->toBeTrue();
});

it('ExportResourcesRequest validates ids and format whitelist', function (): void {
    $user = User::factory()->create();

    $req = new ExportResourcesRequest;
    expect($req->authorize())->toBeFalse();

    $req->setUserResolver(fn () => $user);
    expect($req->authorize())->toBeTrue();

    $rules = $req->rules();
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => [1], 'format' => 'invalid'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['ids' => array_fill(0, 101, 1), 'format' => 'datacite-json'], $rules)->fails())->toBeTrue();
});

/*
 * Preservation of the prior `abort(403, '...')` response contract.
 *
 * When authorization moved from controller-side `abort()` calls into
 * FormRequest::authorize(), Laravel would otherwise return its generic
 * "This action is unauthorized." message. The `failedAuthorization()`
 * overrides keep the original wording so existing UI/API consumers are not
 * broken (Issue: PR #679 review).
 */

it('RegisterResourcesRequest::failedAuthorization preserves the prior 403 message', function (): void {
    $req = new RegisterResourcesRequest;

    $reflection = new ReflectionMethod($req, 'failedAuthorization');
    $reflection->setAccessible(true);

    expect(fn () => $reflection->invoke($req))
        ->toThrow(AuthorizationException::class, 'You are not authorized to register resources.');

    expect(RegisterResourcesRequest::UNAUTHORIZED_MESSAGE)
        ->toBe('You are not authorized to register resources.');
});

it('RegisterIgsnsRequest::failedAuthorization preserves the prior 403 message', function (): void {
    $req = new RegisterIgsnsRequest;

    $reflection = new ReflectionMethod($req, 'failedAuthorization');
    $reflection->setAccessible(true);

    expect(fn () => $reflection->invoke($req))
        ->toThrow(AuthorizationException::class, 'You are not authorized to register IGSNs.');

    expect(RegisterIgsnsRequest::UNAUTHORIZED_MESSAGE)
        ->toBe('You are not authorized to register IGSNs.');
});

it('DestroyIgsnsRequest::failedAuthorization preserves the prior 403 message', function (): void {
    $req = new DestroyIgsnsRequest;

    $reflection = new ReflectionMethod($req, 'failedAuthorization');
    $reflection->setAccessible(true);

    expect(fn () => $reflection->invoke($req))
        ->toThrow(AuthorizationException::class, 'You are not authorized to delete IGSNs.');

    expect(DestroyIgsnsRequest::UNAUTHORIZED_MESSAGE)
        ->toBe('You are not authorized to delete IGSNs.');
});
