<?php

use App\Services\OldDatasetEditorLoader;

// Tests that don't require database connection - test license mapping logic
describe('license mapping', function () {
    it('maps CC BY 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        $result = $method->invoke($loader, 'CC BY 4.0');

        expect($result)->toBe('CC-BY-4.0');
    });

    it('maps CC BY-NC 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        $result = $method->invoke($loader, 'CC BY-NC 4.0');

        expect($result)->toBe('CC-BY-NC-4.0');
    });

    it('maps CC BY-SA 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        $result = $method->invoke($loader, 'CC BY-SA 4.0');

        expect($result)->toBe('CC-BY-SA-4.0');
    });

    it('maps Apache License 2.0 variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'Apache License 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License Version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License, version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License, Version 2.0 (ALv2)'))->toBe('Apache-2.0');
    });

    it('maps GNU GPL v3 variants with copyright correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'GNU General Public License, Version 3, 29 June 2007'))->toBe('GPL-3.0-only')
            ->and($method->invoke($loader, 'GNU General Public License, version 3'))->toBe('GPL-3.0-only')
            ->and($method->invoke($loader, 'GNU General Public License Version 3 (29 June 2007); Copyright (C) 2021 Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences, Potsdam, Germany'))->toBe('GPL-3.0-only');
    });

    it('maps MIT License variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'MIT License'))->toBe('MIT')
            ->and($method->invoke($loader, 'MIT Licence'))->toBe('MIT')
            ->and($method->invoke($loader, 'MIT License, Copyright (c) 2023 Philipp C. Verpoort'))->toBe('MIT');
    });

    it('maps BSD licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'BSD 2-clause "Simplified" License'))->toBe('BSD-2-Clause')
            ->and($method->invoke($loader, 'BSD 3-Clause License'))->toBe('BSD-3-Clause')
            ->and($method->invoke($loader, 'BSD-3 Clause License'))->toBe('BSD-3-Clause');
    });

    it('maps EUPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'EUPL v1.2'))->toBe('EUPL-1.2')
            ->and($method->invoke($loader, 'EUPL-1.2'))->toBe('EUPL-1.2')
            ->and($method->invoke($loader, 'European Union Public Licence (EUPL) v. 1.2'))->toBe('EUPL-1.2');
    });

    it('does not falsely detect ND in license names', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        // This should map to CC-BY-SA-4.0, NOT CC-BY-SA-ND-4.0
        $result = $method->invoke($loader, '(2) Data from model MPI-HM are licensed under CC BY-SA 4.0');

        expect($result)->toBe('CC-BY-SA-4.0');
    });

    it('trims whitespace from license names', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        // Leading space should be trimmed
        $result = $method->invoke($loader, ' Apache License, Version 2.0 (ALv2)');

        expect($result)->toBe('Apache-2.0');
    });

    it('logs warning for unmappable licenses', function () {
        // Create a proper mock that returns itself for chain calls and expects warning
        $logMock = Mockery::mock(\Illuminate\Log\LogManager::class);
        $logMock->shouldReceive('channel')->andReturnSelf();
        // Allow any warning calls (for deprecations), but specifically expect our license warning
        $logMock->shouldReceive('warning')
            ->once()
            ->with('Could not map license from old database', [
                'license_name' => 'Some Unknown License',
            ]);
        $logMock->shouldReceive('warning')->andReturnNull(); // Allow other warnings

        Log::swap($logMock);

        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        // setAccessible() removed - not needed since PHP 8.1 and deprecated in PHP 8.5

        $result = $method->invoke($loader, 'Some Unknown License');

        expect($result)->toBeNull();
    });

    it('handles CC0 variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'CC0 1.0'))->toBe('CC0-1.0')
            ->and($method->invoke($loader, 'CC0'))->toBe('CC0-1.0')
            ->and($method->invoke($loader, 'CC0 Universal 1.0'))->toBe('CC0-1.0');
    });

    it('maps data prefixed licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'Data Licence: CC BY 4.0'))->toBe('CC-BY-4.0')
            ->and($method->invoke($loader, 'Data License: CC BY 4.0'))->toBe('CC-BY-4.0')
            ->and($method->invoke($loader, 'Data: CC BY 4.0'))->toBe('CC-BY-4.0')
            ->and($method->invoke($loader, 'Datasets: CC BY 4.0'))->toBe('CC-BY-4.0')
            ->and($method->invoke($loader, 'Model data are licensed under CC BY 4.0'))->toBe('CC-BY-4.0');
    });

    it('maps code prefixed licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'Code: Apache License, version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Code: MIT Licence'))->toBe('MIT');
    });

    it('handles AGPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'GNU Affero General Public License (AGPL) (Version 3, 19 November 2007)'))->toBe('AGPL-3.0-only')
            ->and($method->invoke($loader, 'GNU Affero General Public License, Version 3, 19 November 2007, Copyright Potsdam Institute for Climate Impact Research'))->toBe('AGPL-3.0-only');
    });

    it('handles LGPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        expect($method->invoke($loader, 'GNU Lesser General Public License v2.1'))->toBe('LGPL-2.1-only')
            ->and($method->invoke($loader, 'GNU Lesser General Public License v 2.1'))->toBe('LGPL-2.1-only')
            ->and($method->invoke($loader, 'GNU Lesser General Public License Version 3 (29 June 2007)'))->toBe('LGPL-3.0-only');
    });

    it('handles ODbL license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        

        $result = $method->invoke($loader, 'Open Data Commons Open Database License (ODbL)');

        expect($result)->toBe('ODbL-1.0');
    });
});
