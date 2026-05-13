<?php

declare(strict_types=1);

use App\Http\Requests\StartSingleOldResourceImportRequest;

/**
 * @param  array<int, mixed>  $arguments
 */
function invokeRequestMethod(StartSingleOldResourceImportRequest $request, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($request, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($request, $arguments);
}

function requestRuleClosure(StartSingleOldResourceImportRequest $request): Closure
{
    $rules = $request->rules()['doi'];

    foreach ($rules as $rule) {
        if ($rule instanceof Closure) {
            return $rule;
        }
    }

    throw new RuntimeException('DOI rules closure not found.');
}

describe('StartSingleOldResourceImportRequest', function () {
    it('authorizes the request', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);

        expect($request->authorize())->toBeTrue();
    });

    it('returns the custom validation messages', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);

        expect($request->messages())->toBe([
            'doi.required' => 'A DOI is required to import a single legacy resource.',
            'doi.string' => 'The DOI must be a string.',
            'doi.max' => 'The DOI must not exceed 255 characters.',
        ]);
    });

    it('normalizes a DOI URL during prepareForValidation and exposes it via getDoi', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', [
            'doi' => ' https://doi.org/10.5880/GFZ.OJSJ.2026.001 ',
        ]);

        invokeRequestMethod($request, 'prepareForValidation');

        expect($request->input('doi'))->toBe('10.5880/gfz.ojsj.2026.001')
            ->and($request->getDoi())->toBe('10.5880/gfz.ojsj.2026.001');
    });

    it('normalizes scalar and non-string DOI inputs consistently', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);

        expect(invokeRequestMethod($request, 'normalizeDoiInput', [null]))->toBeNull()
            ->and(invokeRequestMethod($request, 'normalizeDoiInput', [105880]))->toBe('105880')
            ->and(invokeRequestMethod($request, 'normalizeDoiInput', [['not', 'a', 'string']]))->toBe(['not', 'a', 'string'])
            ->and(invokeRequestMethod($request, 'normalizeDoiInput', ['   ']))->toBeNull();
    });

    it('defines the expected base validation rules', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);
        $rules = $request->rules()['doi'];

        expect($rules)->toContain('required')
            ->and($rules)->toContain('string')
            ->and($rules)->toContain('max:255');
    });

    it('accepts a valid DOI in the custom DOI rule closure', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);
        $rule = requestRuleClosure($request);
        $failures = [];

        $rule('doi', '10.5880/gfz.ojsj.2026.001', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([]);
    });

    it('ignores non-string values in the custom DOI rule closure', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);
        $rule = requestRuleClosure($request);
        $failures = [];

        $rule('doi', ['not-a-string'], function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([]);
    });

    it('rejects invalid DOI values in the custom DOI rule closure', function () {
        $request = StartSingleOldResourceImportRequest::create('/datacite/import/start-single', 'POST', []);
        $rule = requestRuleClosure($request);
        $failures = [];

        $rule('doi', 'not-a-doi', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([
            'Enter a valid DOI in the format 10.xxxx/... or https://doi.org/10.xxxx/....',
        ]);
    });
});