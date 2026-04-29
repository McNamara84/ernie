<?php

declare(strict_types=1);

use App\Http\Requests\Citation\LookupCitationRequest;
use App\Http\Requests\Doi\ResolveDoiRequest;
use Illuminate\Support\Facades\Validator;

covers(LookupCitationRequest::class, ResolveDoiRequest::class);

it('LookupCitationRequest authorizes guests and rejects non-DOI strings', function (): void {
    $request = new LookupCitationRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect($rules)->toHaveKey('doi');

    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['doi' => ''], $rules)->fails())->toBeTrue();
    expect(Validator::make(['doi' => 'not a doi'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['doi' => str_repeat('a', 600)], $rules)->fails())->toBeTrue();

    expect(Validator::make(['doi' => '10.1234/example'], $rules)->fails())->toBeFalse();
    expect(Validator::make(['doi' => 'https://doi.org/10.5880/GFZ.TEST.2024'], $rules)->fails())->toBeFalse();
});

it('ResolveDoiRequest authorizes guests and only requires a non-empty doi string', function (): void {
    $request = new ResolveDoiRequest;
    expect($request->authorize())->toBeTrue();

    $rules = $request->rules();
    expect($rules)->toHaveKey('doi');

    expect(Validator::make([], $rules)->fails())->toBeTrue();
    expect(Validator::make(['doi' => ''], $rules)->fails())->toBeTrue();

    // The controller does its own format validation so any string passes.
    expect(Validator::make(['doi' => 'anything'], $rules)->fails())->toBeFalse();
    expect(Validator::make(['doi' => '10.5880/GFZ.TEST'], $rules)->fails())->toBeFalse();
});
