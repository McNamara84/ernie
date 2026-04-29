<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTTP Layer Architecture Tests
|--------------------------------------------------------------------------
|
| Enforces the FormRequest convention for all HTTP controllers:
|
|   - Controllers MUST NOT call `$request->validate(...)` directly.
|   - Controllers MUST NOT build ad-hoc validators with
|     `Validator::make($request->all(), ...)`.
|
| Validation belongs in dedicated FormRequest classes under
| `app/Http/Requests/`. The allow-list below documents the few endpoints
| where this rule cannot apply:
|
|   - `Api\DataCiteController` and `OrcidController` return a custom JSON
|     error envelope on 422 (`{ success: false, message: 'Validation failed',
|     errors: { ... } }`) which is part of the documented public API
|     contract and cannot be reproduced by Laravel's default FormRequest
|     422 response shape.
|
|   - `ContactMessageController` performs a honeypot check and a per-IP
|     rate-limit BEFORE running validation. Moving validation into a
|     FormRequest would change the failure ordering — bots filling the
|     hidden field would receive a 422 before the silent-success branch
|     fires, defeating the spam protection.
|
*/

use Symfony\Component\Finder\Finder;

/**
 * Allow-list of controller class basenames that may contain inline
 * validation calls. Keep this list intentionally short and document each
 * entry above.
 *
 * @var list<string>
 */
$inlineValidationAllowList = [
    'Api/DataCiteController.php',
    'OrcidController.php',
    'ContactMessageController.php',
];

it('does not call $request->validate() outside the documented allow-list', function () use ($inlineValidationAllowList) {
    $finder = (new Finder)
        ->files()
        ->in(app_path('Http/Controllers'))
        ->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $inlineValidationAllowList, true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (str_contains($contents, '$request->validate(')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'Controllers must use FormRequests instead of inline $request->validate(). '
        .'Add the controller to the allow-list in tests/pest/Arch/HttpLayerTest.php '
        .'only when a custom 422 response shape or pre-validation guard is required.'
    );
});

it('does not build ad-hoc Validators from $request->all() outside the allow-list', function () use ($inlineValidationAllowList) {
    $finder = (new Finder)
        ->files()
        ->in(app_path('Http/Controllers'))
        ->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $inlineValidationAllowList, true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getRealPath());

        // Match `Validator::make($request->all()` (with optional whitespace).
        if (preg_match('/Validator::make\(\s*\$request->all\(\)/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'Controllers must use FormRequests instead of building Validator::make($request->all(), ...) directly.'
    );
});
