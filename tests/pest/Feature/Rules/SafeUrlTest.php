<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\SafeUrl;
use Illuminate\Support\Facades\Validator;

describe('SafeUrl Validation Rule', function () {
    test('accepts valid http URL', function () {
        $validator = Validator::make(
            ['url' => 'http://example.com/data'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts valid https URL', function () {
        $validator = Validator::make(
            ['url' => 'https://datapub.gfz-potsdam.de/download/test.zip'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts null value', function () {
        $validator = Validator::make(
            ['url' => null],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts empty string', function () {
        $validator = Validator::make(
            ['url' => ''],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('rejects javascript: URL', function () {
        $validator = Validator::make(
            ['url' => 'javascript:alert(1)'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
        // PHP 8.5's Uri parser correctly identifies the scheme, so we get the protocol error
        expect($validator->errors()->first('url'))->toContain('http or https');
    });

    test('rejects data: URL', function () {
        $validator = Validator::make(
            ['url' => 'data:text/html,<script>alert(1)</script>'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects vbscript: URL', function () {
        $validator = Validator::make(
            ['url' => 'vbscript:msgbox(1)'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects ftp: URL', function () {
        $validator = Validator::make(
            ['url' => 'ftp://files.example.com/data.zip'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('http or https');
    });

    test('rejects file: URL', function () {
        $validator = Validator::make(
            ['url' => 'file:///etc/passwd'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects URL without scheme', function () {
        $validator = Validator::make(
            ['url' => 'example.com/data'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
        // PHP 8.5's Uri parser treats this as a relative path without scheme
        expect($validator->errors()->first('url'))->toContain('URL scheme');
    });

    test('is case-insensitive for scheme', function () {
        $validator = Validator::make(
            ['url' => 'HTTPS://example.com/data'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('rejects malformed URL', function () {
        $validator = Validator::make(
            ['url' => 'http://'],
            ['url' => ['nullable', new SafeUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });
});
