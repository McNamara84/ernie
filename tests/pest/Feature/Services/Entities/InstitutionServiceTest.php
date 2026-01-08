<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Services\Entities\InstitutionService;

describe('InstitutionService', function () {
    beforeEach(function () {
        $this->service = new InstitutionService;
    });

    describe('findOrCreate', function () {
        it('creates a new institution when none exists', function () {
            $data = [
                'institutionName' => 'Test University',
            ];

            $institution = $this->service->findOrCreate($data);

            expect($institution)->toBeInstanceOf(Institution::class);
            expect($institution->exists)->toBeTrue();
            expect($institution->name)->toBe('Test University');
        });

        it('creates institution with ROR ID', function () {
            $data = [
                'institutionName' => 'GFZ Potsdam',
                'rorId' => 'https://ror.org/04z8jg394',
            ];

            $institution = $this->service->findOrCreate($data);

            expect($institution->name)->toBe('GFZ Potsdam');
            expect($institution->name_identifier)->toBe('https://ror.org/04z8jg394');
            expect($institution->name_identifier_scheme)->toBe('ROR');
        });

        it('finds existing institution by ROR ID', function () {
            $rorId = 'https://ror.org/04z8jg394';
            $existing = Institution::factory()->create([
                'name' => 'Original Name',
                'name_identifier' => $rorId,
                'name_identifier_scheme' => 'ROR',
            ]);

            $data = [
                'institutionName' => 'Different Name',
                'rorId' => $rorId,
            ];

            $institution = $this->service->findOrCreate($data);

            expect($institution->id)->toBe($existing->id);
        });

        it('finds existing institution by name when no identifier', function () {
            $existing = Institution::factory()->create([
                'name' => 'Test University',
                'name_identifier' => null,
            ]);

            $data = [
                'institutionName' => 'Test University',
            ];

            $institution = $this->service->findOrCreate($data);

            expect($institution->id)->toBe($existing->id);
        });

        it('supports alternative key names', function () {
            $data = [
                'name' => 'Alternative Key Name',
                'identifier' => 'https://ror.org/12345',
                'identifierScheme' => 'ROR',
            ];

            $institution = $this->service->findOrCreate($data);

            expect($institution->name)->toBe('Alternative Key Name');
            expect($institution->name_identifier)->toBe('https://ror.org/12345');
        });
    });

    describe('findOrCreateWithIdentifier', function () {
        it('creates institution with explicit parameters', function () {
            $institution = $this->service->findOrCreateWithIdentifier(
                'Explicit University',
                'https://ror.org/explicit',
                'ROR'
            );

            expect($institution->name)->toBe('Explicit University');
            expect($institution->name_identifier)->toBe('https://ror.org/explicit');
            expect($institution->name_identifier_scheme)->toBe('ROR');
        });

        it('updates existing institution name', function () {
            $existing = Institution::factory()->create([
                'name' => 'Old Name',
                'name_identifier' => 'https://ror.org/update',
                'name_identifier_scheme' => 'ROR',
            ]);

            $institution = $this->service->findOrCreateWithIdentifier(
                'New Name',
                'https://ror.org/update',
                'ROR'
            );

            expect($institution->id)->toBe($existing->id);
            expect($institution->fresh()->name)->toBe('New Name');
        });
    });

    describe('findByIdentifierOrName', function () {
        it('prioritizes identifier+scheme search', function () {
            $byIdAndScheme = Institution::factory()->create([
                'name' => 'By ID and Scheme',
                'name_identifier' => 'ror123',
                'name_identifier_scheme' => 'ROR',
            ]);

            Institution::factory()->create([
                'name' => 'By Name Only',
                'name_identifier' => null,
            ]);

            $result = $this->service->findByIdentifierOrName('ror123', 'ROR', 'By Name Only');

            expect($result->id)->toBe($byIdAndScheme->id);
        });

        it('falls back to name search when identifier not found', function () {
            $byName = Institution::factory()->create([
                'name' => 'Fallback University',
                'name_identifier' => null,
            ]);

            $result = $this->service->findByIdentifierOrName('nonexistent', 'ROR', 'Fallback University');

            expect($result->id)->toBe($byName->id);
        });

        it('returns null when nothing matches', function () {
            $result = $this->service->findByIdentifierOrName('none', 'ROR', 'Nonexistent');

            expect($result)->toBeNull();
        });
    });

    describe('findByIdentifier', function () {
        it('finds institution by identifier only', function () {
            $institution = Institution::factory()->create([
                'name' => 'Test',
                'name_identifier' => 'unique-id-123',
            ]);

            $result = $this->service->findByIdentifier('unique-id-123');

            expect($result->id)->toBe($institution->id);
        });

        it('narrows search with scheme', function () {
            Institution::factory()->create([
                'name' => 'Wrong Scheme',
                'name_identifier' => 'same-id',
                'name_identifier_scheme' => 'OTHER',
            ]);

            $correct = Institution::factory()->create([
                'name' => 'Correct Scheme',
                'name_identifier' => 'same-id',
                'name_identifier_scheme' => 'ROR',
            ]);

            $result = $this->service->findByIdentifier('same-id', 'ROR');

            expect($result->id)->toBe($correct->id);
        });
    });

    describe('findOrCreateMslLaboratory', function () {
        it('creates MSL laboratory with labid scheme', function () {
            $data = [
                'identifier' => 'lab-12345',
                'name' => 'High Pressure Lab',
            ];

            $institution = $this->service->findOrCreateMslLaboratory($data);

            expect($institution->name)->toBe('High Pressure Lab');
            expect($institution->name_identifier)->toBe('lab-12345');
            expect($institution->name_identifier_scheme)->toBe('labid');
        });

        it('finds existing MSL laboratory by labid', function () {
            $existing = Institution::factory()->create([
                'name' => 'Existing Lab',
                'name_identifier' => 'lab-existing',
                'name_identifier_scheme' => 'labid',
            ]);

            $data = [
                'identifier' => 'lab-existing',
                'name' => 'Updated Lab Name',
            ];

            $institution = $this->service->findOrCreateMslLaboratory($data);

            expect($institution->id)->toBe($existing->id);
            expect($institution->fresh()->name)->toBe('Updated Lab Name');
        });
    });
});
