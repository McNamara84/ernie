<?php

declare(strict_types=1);

use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Requests\Settings\UpdateFontSizeRequest;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| UpdateFontSizeRequest
|--------------------------------------------------------------------------
*/

describe('UpdateFontSizeRequest', function () {
    it('authorizes all users', function () {
        $request = new UpdateFontSizeRequest;

        expect($request->authorize())->toBeTrue();
    });

    it('requires font_size_preference field', function () {
        $request = new UpdateFontSizeRequest;
        $rules = $request->rules();

        expect($rules['font_size_preference'])->toContain('required');
    });

    it('only accepts regular or large', function () {
        $request = new UpdateFontSizeRequest;
        $rules = $request->rules();

        expect($rules['font_size_preference'])->toContain('in:regular,large');
    });
});

/*
|--------------------------------------------------------------------------
| UpdatePasswordRequest
|--------------------------------------------------------------------------
*/

describe('UpdatePasswordRequest', function () {
    it('requires current_password', function () {
        $request = new UpdatePasswordRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKey('current_password');
        expect($rules['current_password'])->toContain('required');
    });

    it('requires password with confirmation', function () {
        $request = new UpdatePasswordRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKey('password');
        expect($rules['password'])->toContain('required');
        expect($rules['password'])->toContain('confirmed');
    });
});

/*
|--------------------------------------------------------------------------
| ProfileUpdateRequest
|--------------------------------------------------------------------------
*/

describe('ProfileUpdateRequest', function () {
    it('requires name', function () {
        $request = new ProfileUpdateRequest;
        $rules = $request->rules();

        expect($rules['name'])->toContain('required');
        expect($rules['name'])->toContain('string');
    });

    it('requires valid email', function () {
        $request = new ProfileUpdateRequest;
        $rules = $request->rules();

        expect($rules['email'])->toContain('required');
        expect($rules['email'])->toContain('email');
        expect($rules['email'])->toContain('lowercase');
    });
});
