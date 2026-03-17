<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Services\Entities\InstitutionService;

covers(InstitutionService::class);

describe('InstitutionService', function () {
    beforeEach(function () {
        $this->service = new InstitutionService;
    });

    describe('findOrCreate', function () {
        it('creates a new institution when none exists', function () {
            $institution = $this->service->findOrCreate([
                'institutionName' => 'GFZ Potsdam',
            ]);

            expect($institution)->toBeInstanceOf(Institution::class);
            expect($institution->exists)->toBeTrue();
            expect($institution->name)->toBe('GFZ Potsdam');
        });

        it('creates institution with ROR identifier', function () {
            $institution = $this->service->findOrCreate([
                'institutionName' => 'GFZ Potsdam',
                'rorId' => 'https://ror.org/04z8jg394',
            ]);

            expect($institution->name_identifier)->toBe('https://ror.org/04z8jg394');
            expect($institution->name_identifier_scheme)->toBe('ROR');
        });

        it('finds existing institution by ROR identifier', function () {
            $existing = Institution::factory()->withRor('https://ror.org/04z8jg394')->create([
                'name' => 'GFZ Potsdam',
            ]);

            $found = $this->service->findOrCreate([
                'institutionName' => 'Helmholtz-Zentrum Potsdam',
                'rorId' => 'https://ror.org/04z8jg394',
            ]);

            expect($found->id)->toBe($existing->id);
        });

        it('finds existing institution by name when no identifier', function () {
            $existing = Institution::factory()->create([
                'name' => 'MIT',
                'name_identifier' => null,
            ]);

            $found = $this->service->findOrCreate([
                'institutionName' => 'MIT',
            ]);

            expect($found->id)->toBe($existing->id);
        });

        it('uses name key as fallback for institutionName', function () {
            $institution = $this->service->findOrCreate([
                'name' => 'Fallback Name University',
            ]);

            expect($institution->name)->toBe('Fallback Name University');
        });

        it('uses identifierScheme key as fallback', function () {
            $institution = $this->service->findOrCreate([
                'institutionName' => 'Lab A',
                'identifier' => 'lab-123',
                'identifierType' => 'labid',
            ]);

            expect($institution->name_identifier)->toBe('lab-123');
            expect($institution->name_identifier_scheme)->toBe('labid');
        });
    });

    describe('findOrCreateWithIdentifier', function () {
        it('creates institution with explicit parameters', function () {
            $institution = $this->service->findOrCreateWithIdentifier(
                'Test University',
                'https://ror.org/abc123',
                'ROR'
            );

            expect($institution->name)->toBe('Test University');
            expect($institution->name_identifier)->toBe('https://ror.org/abc123');
            expect($institution->name_identifier_scheme)->toBe('ROR');
        });

        it('creates institution without identifier', function () {
            $institution = $this->service->findOrCreateWithIdentifier(
                'Simple University',
                null,
                null
            );

            expect($institution->name)->toBe('Simple University');
            expect($institution->name_identifier)->toBeNull();
        });

        it('updates name of existing institution found by identifier', function () {
            Institution::factory()->withRor('https://ror.org/test123')->create([
                'name' => 'Old Name',
            ]);

            $institution = $this->service->findOrCreateWithIdentifier(
                'New Name',
                'https://ror.org/test123',
                'ROR'
            );

            expect($institution->name)->toBe('New Name');
        });
    });

    describe('findByIdentifierOrName', function () {
        it('returns null when nothing matches', function () {
            $result = $this->service->findByIdentifierOrName(null, null, 'Nonexistent');

            expect($result)->toBeNull();
        });

        it('finds by identifier and scheme', function () {
            $existing = Institution::factory()->create([
                'name' => 'CERN',
                'name_identifier' => 'https://ror.org/cern123',
                'name_identifier_scheme' => 'ROR',
            ]);

            $result = $this->service->findByIdentifierOrName(
                'https://ror.org/cern123',
                'ROR',
                'Anything'
            );

            expect($result->id)->toBe($existing->id);
        });

        it('finds by identifier only when scheme does not match', function () {
            $existing = Institution::factory()->create([
                'name' => 'NASA',
                'name_identifier' => 'nasa-001',
                'name_identifier_scheme' => 'custom',
            ]);

            $result = $this->service->findByIdentifierOrName(
                'nasa-001',
                'ROR',
                'NASA'
            );

            expect($result->id)->toBe($existing->id);
        });

        it('falls back to name search without identifier', function () {
            $existing = Institution::factory()->create([
                'name' => 'DLR',
                'name_identifier' => null,
            ]);

            $result = $this->service->findByIdentifierOrName(null, null, 'DLR');

            expect($result->id)->toBe($existing->id);
        });

        it('does not find by name if institution has an identifier', function () {
            Institution::factory()->withRor('https://ror.org/dlr123')->create([
                'name' => 'DLR Identified',
            ]);

            $result = $this->service->findByIdentifierOrName(null, null, 'DLR Identified');

            expect($result)->toBeNull();
        });
    });

    describe('findByIdentifier', function () {
        it('finds by identifier without scheme', function () {
            $existing = Institution::factory()->create([
                'name_identifier' => 'abc-123',
            ]);

            $result = $this->service->findByIdentifier('abc-123');

            expect($result->id)->toBe($existing->id);
        });

        it('finds by identifier with scheme', function () {
            Institution::factory()->create([
                'name_identifier' => 'xyz-789',
                'name_identifier_scheme' => 'labid',
            ]);

            $result = $this->service->findByIdentifier('xyz-789', 'labid');

            expect($result)->not->toBeNull();
            expect($result->name_identifier_scheme)->toBe('labid');
        });

        it('returns null when identifier not found', function () {
            $result = $this->service->findByIdentifier('nonexistent');

            expect($result)->toBeNull();
        });
    });
});
