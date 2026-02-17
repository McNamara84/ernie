<?php

declare(strict_types=1);

use App\Models\Institution;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Institution model with identifiers', function () {
    test('can create institution with labid', function () {
        $institution = Institution::create([
            'name' => 'Test Laboratory',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test Laboratory',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($institution->name_identifier)->toBe('abc123')
            ->and($institution->name_identifier_scheme)->toBe('labid');
    });

    test('can create institution with ror', function () {
        $institution = Institution::create([
            'name' => 'Test University',
            'name_identifier' => 'https://ror.org/test123',
            'name_identifier_scheme' => 'ROR',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test University',
            'name_identifier' => 'https://ror.org/test123',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->name_identifier)->toBe('https://ror.org/test123')
            ->and($institution->name_identifier_scheme)->toBe('ROR');
    });

    test('ror_id accessor returns name_identifier for ROR institutions', function () {
        $institution = Institution::create([
            'name' => 'ROR University',
            'name_identifier' => 'https://ror.org/legacy',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->ror_id)->toBe('https://ror.org/legacy');
    });

    test('ror_id accessor returns null for non-ROR institutions', function () {
        $institution = Institution::create([
            'name' => 'Lab',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($institution->ror_id)->toBeNull();
    });

    test('can create institution without identifier', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Simple Institution',
            'name_identifier' => null,
            'name_identifier_scheme' => null,
        ]);

        expect($institution->name_identifier)->toBeNull()
            ->and($institution->name_identifier_scheme)->toBeNull();
    });
});

describe('Institution type checks', function () {
    test('isLaboratory returns true for labid', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($lab->isLaboratory())->toBeTrue();
    });

    test('isLaboratory returns false for non-labid', function () {
        $institution = Institution::create([
            'name' => 'Test University',
            'name_identifier' => 'https://ror.org/test',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    test('isLaboratory returns false when no identifier scheme', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    test('hasRor returns true for ROR', function () {
        $institution = Institution::create([
            'name' => 'ROR University',
            'name_identifier' => 'https://ror.org/test',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->hasRor())->toBeTrue();
    });

    test('hasRor returns false for non-ROR', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($lab->hasRor())->toBeFalse();
    });

    test('hasRor returns false when no identifier scheme', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->hasRor())->toBeFalse();
    });
});

describe('Institution constraints', function () {
    test('unique constraint on name and name_identifier', function () {
        Institution::create([
            'name' => 'First Lab',
            'name_identifier' => 'same123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect(fn () => Institution::create([
            'name' => 'First Lab',
            'name_identifier' => 'same123',
            'name_identifier_scheme' => 'labid',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    test('same name_identifier with different name is allowed', function () {
        $first = Institution::create([
            'name' => 'Lab A',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        $second = Institution::create([
            'name' => 'Lab B',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($first->id)->not->toBe($second->id);
    });
});

describe('Institution updates', function () {
    test('can update institution name_identifier', function () {
        $institution = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'old123',
            'name_identifier_scheme' => 'labid',
        ]);

        $institution->update(['name_identifier' => 'new456']);

        expect($institution->fresh()->name_identifier)->toBe('new456');
    });

    test('can update institution name', function () {
        $institution = Institution::create([
            'name' => 'Old Name',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        $institution->update(['name' => 'New Name']);

        expect($institution->fresh()->name)->toBe('New Name');
    });

    test('fillable includes correct fields', function () {
        $institution = new Institution;
        $fillable = $institution->getFillable();

        expect($fillable)
            ->toContain('name')
            ->toContain('name_identifier')
            ->toContain('name_identifier_scheme')
            ->toContain('name_identifier_scheme_uri');
    });
});
