<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('IdentifierTypePattern', function (): void {
    test('belongs to an identifier type', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        $pattern = IdentifierTypePattern::create([
            'identifier_type_id' => $type->id,
            'type' => 'validation',
            'pattern' => '^10\.\d{4,}',
            'is_active' => true,
            'priority' => 10,
        ]);

        expect($pattern->identifierType)->toBeInstanceOf(IdentifierType::class)
            ->and($pattern->identifierType->slug)->toBe('DOI');
    });

    test('casts is_active to boolean', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        $pattern = IdentifierTypePattern::create([
            'identifier_type_id' => $type->id,
            'type' => 'detection',
            'pattern' => '^doi:',
            'is_active' => true,
            'priority' => 0,
        ]);

        expect($pattern->is_active)->toBeBool()->toBeTrue();
    });

    test('active scope returns only active patterns', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'a', 'is_active' => true, 'priority' => 0]);
        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'b', 'is_active' => false, 'priority' => 0]);

        expect(IdentifierTypePattern::active()->count())->toBe(1);
    });

    test('validation scope returns only validation type', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'a', 'is_active' => true, 'priority' => 0]);
        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'detection', 'pattern' => 'b', 'is_active' => true, 'priority' => 0]);

        expect(IdentifierTypePattern::validation()->count())->toBe(1);
    });

    test('detection scope returns only detection type', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'a', 'is_active' => true, 'priority' => 0]);
        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'detection', 'pattern' => 'b', 'is_active' => true, 'priority' => 0]);

        expect(IdentifierTypePattern::detection()->count())->toBe(1);
    });

    test('identifier type has many patterns', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'a', 'is_active' => true, 'priority' => 0]);
        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'detection', 'pattern' => 'b', 'is_active' => true, 'priority' => 0]);

        expect($type->patterns)->toHaveCount(2);
    });

    test('patterns are deleted when identifier type is deleted', function (): void {
        $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]);

        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'validation', 'pattern' => 'a', 'is_active' => true, 'priority' => 0]);
        IdentifierTypePattern::create(['identifier_type_id' => $type->id, 'type' => 'detection', 'pattern' => 'b', 'is_active' => true, 'priority' => 0]);

        $type->delete();

        expect(IdentifierTypePattern::count())->toBe(0);
    });
});
