<?php

use App\Services\OldDatasetEditorLoader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Tests that require database connection
describe('with metaworks database', function () {
    beforeEach(function () {
        // Skip if metaworks connection is not configured
        try {
            DB::connection('metaworks')->getPdo();
        } catch (Exception $e) {
            $this->markTestSkipped('Metaworks database connection not available');
        }
    });

    it('loads a dataset from old database', function () {
        // Test with Resource ID 2413 (known to exist)
        $loader = new OldDatasetEditorLoader;
        $data = $loader->loadForEditor(2413);

        expect($data)->toBeArray()
            ->and($data)->toHaveKey('titles')
            ->and($data)->toHaveKey('authors')
            ->and($data)->toHaveKey('descriptions')
            ->and($data)->toHaveKey('licenses');
    });

    it('transforms title type correctly for main titles', function () {
        $loader = new OldDatasetEditorLoader;
        $data = $loader->loadForEditor(2413);

        // Check that at least one title has 'main-title' as titleType
        $hasMainTitle = false;
        foreach ($data['titles'] as $title) {
            if ($title['titleType'] === 'main-title') {
                $hasMainTitle = true;
                break;
            }
        }

        expect($hasMainTitle)->toBeTrue();
    });
});

// Tests that don't require database connection - test license mapping logic
describe('license mapping', function () {
    it('maps CC BY 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($loader, 'CC BY 4.0');

        expect($result)->toBe('CC-BY-4.0');
    });

    it('maps CC BY-NC 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($loader, 'CC BY-NC 4.0');

        expect($result)->toBe('CC-BY-NC-4.0');
    });

    it('maps CC BY-SA 4.0 license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($loader, 'CC BY-SA 4.0');

        expect($result)->toBe('CC-BY-SA-4.0');
    });

    it('maps Apache License 2.0 variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'Apache License 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License Version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License, version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Apache License, Version 2.0 (ALv2)'))->toBe('Apache-2.0');
    });

    it('maps GNU GPL v3 variants with copyright correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'GNU General Public License, Version 3, 29 June 2007'))->toBe('GPL-3.0-only')
            ->and($method->invoke($loader, 'GNU General Public License, version 3'))->toBe('GPL-3.0-only')
            ->and($method->invoke($loader, 'GNU General Public License Version 3 (29 June 2007); Copyright (C) 2021 Helmholtz Centre Potsdam GFZ German Research Centre for Geosciences, Potsdam, Germany'))->toBe('GPL-3.0-only');
    });

    it('maps MIT License variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'MIT License'))->toBe('MIT')
            ->and($method->invoke($loader, 'MIT Licence'))->toBe('MIT')
            ->and($method->invoke($loader, 'MIT License, Copyright (c) 2023 Philipp C. Verpoort'))->toBe('MIT');
    });

    it('maps BSD licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'BSD 2-clause "Simplified" License'))->toBe('BSD-2-Clause')
            ->and($method->invoke($loader, 'BSD 3-Clause License'))->toBe('BSD-3-Clause')
            ->and($method->invoke($loader, 'BSD-3 Clause License'))->toBe('BSD-3-Clause');
    });

    it('maps EUPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'EUPL v1.2'))->toBe('EUPL-1.2')
            ->and($method->invoke($loader, 'EUPL-1.2'))->toBe('EUPL-1.2')
            ->and($method->invoke($loader, 'European Union Public Licence (EUPL) v. 1.2'))->toBe('EUPL-1.2');
    });

    it('does not falsely detect ND in license names', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        // This should map to CC-BY-SA-4.0, NOT CC-BY-SA-ND-4.0
        $result = $method->invoke($loader, '(2) Data from model MPI-HM are licensed under CC BY-SA 4.0');

        expect($result)->toBe('CC-BY-SA-4.0');
    });

    it('trims whitespace from license names', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        // Leading space should be trimmed
        $result = $method->invoke($loader, ' Apache License, Version 2.0 (ALv2)');

        expect($result)->toBe('Apache-2.0');
    });

    it('logs warning for unmappable licenses', function () {
        Log::shouldReceive('warning')
            ->once()
            ->with('Could not map license from old database', [
                'license_name' => 'Some Unknown License',
            ]);

        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($loader, 'Some Unknown License');

        expect($result)->toBeNull();
    });

    it('handles CC0 variants correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'CC0 1.0'))->toBe('CC0-1.0')
            ->and($method->invoke($loader, 'CC0'))->toBe('CC0-1.0')
            ->and($method->invoke($loader, 'CC0 Universal 1.0'))->toBe('CC0-1.0');
    });

    it('maps data prefixed licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

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
        $method->setAccessible(true);

        expect($method->invoke($loader, 'Code: Apache License, version 2.0'))->toBe('Apache-2.0')
            ->and($method->invoke($loader, 'Code: MIT Licence'))->toBe('MIT');
    });

    it('handles AGPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'GNU Affero General Public License (AGPL) (Version 3, 19 November 2007)'))->toBe('AGPL-3.0-only')
            ->and($method->invoke($loader, 'GNU Affero General Public License, Version 3, 19 November 2007, Copyright Potsdam Institute for Climate Impact Research'))->toBe('AGPL-3.0-only');
    });

    it('handles LGPL licenses correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        expect($method->invoke($loader, 'GNU Lesser General Public License v2.1'))->toBe('LGPL-2.1-only')
            ->and($method->invoke($loader, 'GNU Lesser General Public License v 2.1'))->toBe('LGPL-2.1-only')
            ->and($method->invoke($loader, 'GNU Lesser General Public License Version 3 (29 June 2007)'))->toBe('LGPL-3.0-only');
    });

    it('handles ODbL license correctly', function () {
        $loader = new OldDatasetEditorLoader;
        $reflection = new ReflectionClass($loader);
        $method = $reflection->getMethod('mapLicenseNameToIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($loader, 'Open Data Commons Open Database License (ODbL)');

        expect($result)->toBe('ODbL-1.0');
    });
});
