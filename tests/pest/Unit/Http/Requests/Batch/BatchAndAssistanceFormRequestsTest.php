<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Assistance\DeclineSuggestionRequest;
use App\Http\Requests\Batch\DestroyIgsnsRequest;
use App\Http\Requests\Batch\ExportResourcesRequest;
use App\Http\Requests\Batch\RegisterIgsnsRequest;
use App\Http\Requests\Batch\RegisterResourcesRequest;
use App\Models\User;
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
