<?php

declare(strict_types=1);

use App\Http\Requests\StartSingleIgsnImportRequest;
use Illuminate\Support\Facades\Config;

/**
 * @param  array<int, mixed>  $arguments
 */
function invokeSingleIgsnRequestMethod(StartSingleIgsnImportRequest $request, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($request, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($request, $arguments);
}

function singleIgsnRequestRuleClosure(StartSingleIgsnImportRequest $request): Closure
{
    $rules = $request->rules()['igsn'];

    foreach ($rules as $rule) {
        if ($rule instanceof Closure) {
            return $rule;
        }
    }

    throw new RuntimeException('IGSN rules closure not found.');
}

beforeEach(function (): void {
    Config::set('datacite.production.igsn_prefix', '10.60510');
});

describe('StartSingleIgsnImportRequest', function (): void {
    it('authorizes the request', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);

        expect($request->authorize())->toBeTrue();
    });

    it('returns the custom validation messages', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);

        expect($request->messages())->toBe([
            'igsn.required' => 'An IGSN is required to import a single sample.',
            'igsn.string' => 'The IGSN must be a string.',
            'igsn.max' => 'The IGSN must not exceed 255 characters.',
        ]);
    });

    it('normalizes an IGSN DOI URL during prepareForValidation and exposes DOI plus handle', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', [
            'igsn' => ' https://doi.org/10.60510/ICDP5052EUYY001 ',
        ]);

        invokeSingleIgsnRequestMethod($request, 'prepareForValidation');

        expect($request->input('igsn'))->toBe('10.60510/icdp5052euyy001')
            ->and($request->getDoi())->toBe('10.60510/icdp5052euyy001')
            ->and($request->getHandle())->toBe('ICDP5052EUYY001');
    });

    it('keeps an invalid value unchanged during prepareForValidation', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', [
            'igsn' => '10.99999/not-this-prefix',
        ]);

        invokeSingleIgsnRequestMethod($request, 'prepareForValidation');

        expect($request->input('igsn'))->toBe('10.99999/not-this-prefix');
    });

    it('defines the expected base validation rules', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);
        $rules = $request->rules()['igsn'];

        expect($rules)->toContain('required')
            ->and($rules)->toContain('string')
            ->and($rules)->toContain('max:255');
    });

    it('accepts valid IGSN values in the custom rule closure', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);
        $rule = singleIgsnRequestRuleClosure($request);
        $failures = [];

        $rule('igsn', 'ICDP5052EUYY001', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([]);
    });

    it('ignores non-string values in the custom rule closure', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);
        $rule = singleIgsnRequestRuleClosure($request);
        $failures = [];

        $rule('igsn', ['not-a-string'], function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([]);
    });

    it('rejects invalid IGSN values in the custom rule closure', function (): void {
        $request = StartSingleIgsnImportRequest::create('/igsns/import/start-single', 'POST', []);
        $rule = singleIgsnRequestRuleClosure($request);
        $failures = [];

        $rule('igsn', '10.99999/not-this-prefix', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        expect($failures)->toBe([
            'Enter a valid IGSN handle or DOI using the configured IGSN prefix.',
        ]);
    });
});
