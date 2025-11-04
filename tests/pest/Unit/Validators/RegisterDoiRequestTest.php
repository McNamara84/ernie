<?php

use App\Http\Requests\RegisterDoiRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

uses(\Tests\TestCase::class);

test('prefix validation passes with valid test prefix in test mode', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        ['prefix' => '10.83279'],
        $request->rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('prefix validation passes with valid production prefix in production mode', function () {
    config([
        'datacite.test_mode' => false,
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        ['prefix' => '10.5880'],
        $request->rules()
    );

    expect($validator->passes())->toBeTrue();
});

test('prefix validation fails with production prefix in test mode', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        ['prefix' => '10.5880'],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('prefix'))
        ->toContain('invalid');
});

test('prefix validation fails with test prefix in production mode', function () {
    config([
        'datacite.test_mode' => false,
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        ['prefix' => '10.83279'],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('prefix'))
        ->toContain('invalid');
});

test('prefix validation fails with invalid prefix format', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        ['prefix' => 'invalid-prefix'],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
});

test('prefix validation fails when prefix is missing', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $request = new RegisterDoiRequest();
    $validator = Validator::make(
        [],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('prefix'))->toBeTrue();
});

test('prefix validation accepts all valid test prefixes', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $testPrefixes = ['10.83279', '10.83186', '10.83114'];

    foreach ($testPrefixes as $prefix) {
        $request = new RegisterDoiRequest();
        $validator = Validator::make(
            ['prefix' => $prefix],
            $request->rules()
        );

        expect($validator->passes())
            ->toBeTrue("Prefix {$prefix} should be valid in test mode");
    }
});

test('prefix validation accepts all valid production prefixes', function () {
    config([
        'datacite.test_mode' => false,
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $productionPrefixes = ['10.5880', '10.26026', '10.14470'];

    foreach ($productionPrefixes as $prefix) {
        $request = new RegisterDoiRequest();
        $validator = Validator::make(
            ['prefix' => $prefix],
            $request->rules()
        );

        expect($validator->passes())
            ->toBeTrue("Prefix {$prefix} should be valid in production mode");
    }
});
