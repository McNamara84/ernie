<?php

declare(strict_types=1);

use App\Http\Requests\Auth\NewPasswordRequest;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Http\Requests\Auth\ResendWelcomeEmailRequest;
use App\Http\Requests\Auth\SetWelcomePasswordRequest;
use Illuminate\Support\Facades\Validator;

covers(
    SetWelcomePasswordRequest::class,
    ResendWelcomeEmailRequest::class,
    PasswordResetLinkRequest::class,
    NewPasswordRequest::class,
);

it('SetWelcomePasswordRequest authorizes guests and rejects weak/unconfirmed passwords', function (): void {
    $request = new SetWelcomePasswordRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect($rules)->toHaveKey('password');

    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['password' => 'short'], $rules)->fails())->toBeTrue();
    expect(Validator::make([
        'password' => 'StrongPass123!',
        'password_confirmation' => 'mismatch',
    ], $rules)->fails())->toBeTrue();

    expect(Validator::make([
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ], $rules)->fails())->toBeFalse();
});

it('ResendWelcomeEmailRequest validates a single e-mail field', function (): void {
    $request = new ResendWelcomeEmailRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['email' => 'not-an-email'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['email' => 'user@example.com'], $rules)->fails())->toBeFalse();
});

it('PasswordResetLinkRequest validates a single e-mail field', function (): void {
    $request = new PasswordResetLinkRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['email' => 'not-an-email'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['email' => 'user@example.com'], $rules)->fails())->toBeFalse();
});

it('NewPasswordRequest requires token, email and a confirmed password', function (): void {
    $request = new NewPasswordRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect($rules)->toHaveKeys(['token', 'email', 'password']);

    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make([
        'token' => 'abc',
        'email' => 'user@example.com',
        'password' => 'StrongPass123!',
    ], $rules)->fails())->toBeTrue(); // missing confirmation

    expect(Validator::make([
        'token' => 'abc',
        'email' => 'user@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ], $rules)->fails())->toBeFalse();
});
