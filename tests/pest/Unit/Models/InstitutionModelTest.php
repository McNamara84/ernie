<?php

declare(strict_types=1);

use App\Models\Institution;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Institution model with identifiers', function () {
    test('can create institution with labid', function () {
        $institution = Institution::create([
            'name' => 'Test Laboratory',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test Laboratory',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        expect($institution->identifier)->toBe('abc123')
            ->and($institution->identifier_type)->toBe('labid');
    });

    test('can create institution with ror', function () {
        $institution = Institution::create([
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test123',
            'identifier_type' => 'ROR',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test123',
            'identifier_type' => 'ROR',
        ]);

        expect($institution->identifier)->toBe('https://ror.org/test123')
            ->and($institution->identifier_type)->toBe('ROR');
    });

    test('can create institution with legacy ror_id', function () {
        $institution = Institution::create([
            'name' => 'Legacy University',
            'ror_id' => 'https://ror.org/legacy',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Legacy University',
            'ror_id' => 'https://ror.org/legacy',
        ]);

        expect($institution->ror_id)->toBe('https://ror.org/legacy');
    });

    test('can create institution without identifier', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Simple Institution',
            'identifier' => null,
            'identifier_type' => null,
        ]);

        expect($institution->identifier)->toBeNull()
            ->and($institution->identifier_type)->toBeNull();
    });
});

describe('Institution type checks', function () {
    test('isLaboratory returns true for labid', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        expect($lab->isLaboratory())->toBeTrue();
    });

    test('isLaboratory returns false for non-labid', function () {
        $institution = Institution::create([
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test',
            'identifier_type' => 'ROR',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    test('isLaboratory returns false when no identifier type', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    test('isRorInstitution returns true for ROR', function () {
        $institution = Institution::create([
            'name' => 'ROR University',
            'identifier' => 'https://ror.org/test',
            'identifier_type' => 'ROR',
        ]);

        expect($institution->isRorInstitution())->toBeTrue();
    });

    test('isRorInstitution returns false for non-ROR', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        expect($lab->isRorInstitution())->toBeFalse();
    });

    test('isRorInstitution returns false when no identifier type', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->isRorInstitution())->toBeFalse();
    });
});

describe('Institution constraints', function () {
    test('unique constraint on identifier and type', function () {
        Institution::create([
            'name' => 'First Lab',
            'identifier' => 'same123',
            'identifier_type' => 'labid',
        ]);

        expect(fn () => Institution::create([
            'name' => 'Second Lab',
            'identifier' => 'same123',
            'identifier_type' => 'labid',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    test('same identifier with different type is allowed', function () {
        $lab = Institution::create([
            'name' => 'Lab',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        $other = Institution::create([
            'name' => 'Other',
            'identifier' => 'abc123',
            'identifier_type' => 'ISNI',
        ]);

        expect($lab->id)->not->toBe($other->id);
    });
});

describe('Institution updates', function () {
    test('can update institution identifier', function () {
        $institution = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'old123',
            'identifier_type' => 'labid',
        ]);

        $institution->update(['identifier' => 'new456']);

        expect($institution->fresh()->identifier)->toBe('new456');
    });

    test('can update institution name', function () {
        $institution = Institution::create([
            'name' => 'Old Name',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        $institution->update(['name' => 'New Name']);

        expect($institution->fresh()->name)->toBe('New Name');
    });

    test('fillable includes new fields', function () {
        $institution = new Institution;
        $fillable = $institution->getFillable();

        expect($fillable)
            ->toContain('identifier')
            ->toContain('identifier_type')
            ->toContain('name')
            ->toContain('ror_id');
    });
});
