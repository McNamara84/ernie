<?php

declare(strict_types=1);

use App\Http\Requests\StartDatacenterOldResourceImportRequest;

describe('StartDatacenterOldResourceImportRequest', function (): void {
    it('authorizes the request and defines the expected rules', function (): void {
        $request = StartDatacenterOldResourceImportRequest::create(
            '/datacite/import/start-datacenter',
            'POST',
        );

        expect($request->authorize())->toBeTrue()
            ->and($request->rules())->toBe([
                'datacenter_id' => ['required', 'string', 'max:255'],
            ]);
    });

    it('trims the selected datacenter id before validation', function (): void {
        $request = StartDatacenterOldResourceImportRequest::create(
            '/datacite/import/start-datacenter',
            'POST',
            ['datacenter_id' => '  DOIDB.RIESGOS  '],
        );
        $method = new ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        expect($request->getDatacenterId())->toBe('DOIDB.RIESGOS');
    });

    it('returns specific validation messages', function (): void {
        $request = StartDatacenterOldResourceImportRequest::create(
            '/datacite/import/start-datacenter',
            'POST',
        );

        expect($request->messages())->toBe([
            'datacenter_id.required' => 'Select a datacenter to start the import.',
            'datacenter_id.string' => 'The selected datacenter is invalid.',
            'datacenter_id.max' => 'The selected datacenter is invalid.',
        ]);
    });
});
