<?php

declare(strict_types=1);

namespace Tests\Feature\Rules;

use App\Rules\SafeDomainUrl;
use Illuminate\Support\Facades\Validator;

covers(SafeDomainUrl::class);

describe('SafeDomainUrl Validation Rule', function () {
    // --- Accepted inputs ---

    test('accepts valid https domain with trailing slash', function () {
        $validator = Validator::make(
            ['url' => 'https://geofon.gfz.de/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts valid https domain without trailing slash', function () {
        $validator = Validator::make(
            ['url' => 'https://data.gfz.de'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts valid http domain', function () {
        $validator = Validator::make(
            ['url' => 'http://example.org/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts domain with port', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org:8443/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts domain with subdomain', function () {
        $validator = Validator::make(
            ['url' => 'https://data.research.gfz-potsdam.de/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('is case-insensitive for scheme', function () {
        $validator = Validator::make(
            ['url' => 'HTTPS://example.org/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts null value (skips validation)', function () {
        $validator = Validator::make(
            ['url' => null],
            ['url' => ['nullable', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    test('accepts empty string (skips validation)', function () {
        $validator = Validator::make(
            ['url' => ''],
            ['url' => ['nullable', new SafeDomainUrl]]
        );

        expect($validator->passes())->toBeTrue();
    });

    // --- Rejected inputs: scheme ---

    test('rejects ftp scheme', function () {
        $validator = Validator::make(
            ['url' => 'ftp://files.example.org/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('http or https');
    });

    test('rejects javascript scheme', function () {
        $validator = Validator::make(
            ['url' => 'javascript:alert(1)'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects data scheme', function () {
        $validator = Validator::make(
            ['url' => 'data:text/html,test'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects URL without scheme', function () {
        $validator = Validator::make(
            ['url' => 'example.org'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('URL scheme');
    });

    // --- Rejected inputs: host ---

    test('rejects URL without host', function () {
        $validator = Validator::make(
            ['url' => 'http://'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    // --- Rejected inputs: credentials ---

    test('rejects URL with userinfo', function () {
        $validator = Validator::make(
            ['url' => 'https://user:pass@example.org/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('credentials');
    });

    test('rejects URL with username only', function () {
        $validator = Validator::make(
            ['url' => 'https://admin@example.org/'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('credentials');
    });

    // --- Rejected inputs: path ---

    test('rejects URL with path', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/some/path'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('without a path');
    });

    test('rejects URL with single path segment', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/path'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('without a path');
    });

    // --- Rejected inputs: query ---

    test('rejects URL with query string', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/?q=search'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('query string');
    });

    test('rejects URL with empty query string', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/?'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('query string');
    });

    // --- Rejected inputs: fragment ---

    test('rejects URL with fragment', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/#section'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('fragment');
    });

    test('rejects URL with empty fragment', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/#'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('url'))->toContain('fragment');
    });

    // --- Rejected inputs: malformed ---

    test('rejects completely invalid URL', function () {
        $validator = Validator::make(
            ['url' => 'not-a-url-at-all'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    // --- Rejected inputs: combined violations ---

    test('rejects URL with path and query', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/page?id=1'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });

    test('rejects URL with path, query, and fragment', function () {
        $validator = Validator::make(
            ['url' => 'https://example.org/page?id=1#top'],
            ['url' => ['required', new SafeDomainUrl]]
        );

        expect($validator->fails())->toBeTrue();
    });
});
