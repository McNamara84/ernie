<?php

declare(strict_types=1);

use App\Models\Institution;
use Illuminate\Database\QueryException;

covers(Institution::class);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('CRUD operations', function () {
    it('can create institution with labid', function () {
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

        expect($institution->name_identifier)->toBe('abc123');
        expect($institution->name_identifier_scheme)->toBe('labid');
    });

    it('can create institution with ROR', function () {
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

        expect($institution->name_identifier)->toBe('https://ror.org/test123');
        expect($institution->name_identifier_scheme)->toBe('ROR');
    });

    it('provides ror_id via accessor for ROR institutions', function () {
        $institution = Institution::create([
            'name' => 'ROR University',
            'name_identifier' => 'https://ror.org/legacy',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->ror_id)->toBe('https://ror.org/legacy');
    });

    it('returns null ror_id for non-ROR institutions', function () {
        $institution = Institution::create([
            'name' => 'Lab',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($institution->ror_id)->toBeNull();
    });

    it('can create institution without identifier', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Simple Institution',
            'name_identifier' => null,
            'name_identifier_scheme' => null,
        ]);

        expect($institution->name_identifier)->toBeNull();
        expect($institution->name_identifier_scheme)->toBeNull();
    });

    it('can update institution identifier', function () {
        $institution = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'old123',
            'name_identifier_scheme' => 'labid',
        ]);

        $institution->update([
            'name_identifier' => 'new456',
        ]);

        expect($institution->fresh()->name_identifier)->toBe('new456');
    });

    it('can update institution name', function () {
        $institution = Institution::create([
            'name' => 'Old Name',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        $institution->update([
            'name' => 'New Name',
        ]);

        expect($institution->fresh()->name)->toBe('New Name');
    });

    it('fillable includes correct fields', function () {
        $institution = new Institution;
        $fillable = $institution->getFillable();

        expect($fillable)->toContain('name');
        expect($fillable)->toContain('name_identifier');
        expect($fillable)->toContain('name_identifier_scheme');
        expect($fillable)->toContain('name_identifier_scheme_uri');
    });
});

describe('Identifier Types', function () {
    it('isLaboratory returns true for labid', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($lab->isLaboratory())->toBeTrue();
    });

    it('isLaboratory returns false for non-labid', function () {
        $institution = Institution::create([
            'name' => 'Test University',
            'name_identifier' => 'https://ror.org/test',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    it('isLaboratory returns false when no identifier scheme', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->isLaboratory())->toBeFalse();
    });

    it('hasRor returns true for ROR', function () {
        $institution = Institution::create([
            'name' => 'ROR University',
            'name_identifier' => 'https://ror.org/test',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($institution->hasRor())->toBeTrue();
    });

    it('hasRor returns false for non-ROR', function () {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'name_identifier' => 'lab123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($lab->hasRor())->toBeFalse();
    });

    it('hasRor returns false when no identifier scheme', function () {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        expect($institution->hasRor())->toBeFalse();
    });
});

describe('Constraints', function () {
    it('enforces unique constraint on name and name_identifier', function () {
        Institution::create([
            'name' => 'Same Name',
            'name_identifier' => 'same123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect(fn () => Institution::create([
            'name' => 'Same Name',
            'name_identifier' => 'same123',
            'name_identifier_scheme' => 'ROR',
        ]))->toThrow(QueryException::class);
    });

    it('allows same name_identifier with different name', function () {
        $first = Institution::create([
            'name' => 'First Institution',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        $second = Institution::create([
            'name' => 'Second Institution',
            'name_identifier' => 'abc123',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($first->id)->not->toBe($second->id);
    });
});
