<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Database\Seeders\IdentifierTypePatternSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Illuminate\Database\Migrations\Migration;

function elmoPatternCorrectionMigration(): Migration
{
    return require database_path('migrations/2026_10_16_000001_correct_elmo_identifier_type_patterns.php');
}

function seedPreviousElmoPatterns(): array
{
    $corrections = require database_path('data/identifier-patterns/2026-10-16.php');

    foreach ($corrections as $slug => $patterns) {
        $type = IdentifierType::create(['name' => $slug, 'slug' => $slug, 'is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);

        foreach ($patterns as $pattern) {
            $type->patterns()->firstOrCreate(
                ['type' => $pattern['type'], 'pattern' => $pattern['previous']],
                ['priority' => $pattern['previous_priority'], 'is_active' => true],
            );
        }
    }

    return $corrections;
}

it('upgrades every original standard pattern without activating identifier types', function (): void {
    $corrections = seedPreviousElmoPatterns();
    elmoPatternCorrectionMigration()->up();

    foreach ($corrections as $slug => $patterns) {
        $type = IdentifierType::where('slug', $slug)->firstOrFail();
        expect($type->is_active)->toBeFalse()->and($type->is_elmo_active)->toBeFalse()->and($type->is_elmo_msl_active)->toBeFalse();

        foreach ($patterns as $pattern) {
            $current = $type->patterns()->where('type', $pattern['type'])->where('pattern', $pattern['pattern'])->firstOrFail();
            expect($current->priority)->toBe($pattern['priority'])->and($current->is_active)->toBeTrue();
            expect($type->patterns()->where('type', $pattern['type'])->where('pattern', $pattern['previous'])->exists())->toBeFalse();
        }
    }
});

it('retains the row id and custom priority when replacing a disabled standard pattern', function (): void {
    seedPreviousElmoPatterns();
    $type = IdentifierType::where('slug', 'arXiv')->firstOrFail();
    $previous = $type->patterns()->where('type', 'validation')->orderByDesc('priority')->firstOrFail();
    $previous->update(['is_active' => false, 'priority' => 42]);
    $id = $previous->id;

    elmoPatternCorrectionMigration()->up();

    $snapshot = require database_path('data/identifier-patterns/2026-10-16.php');
    $replacements = array_filter($snapshot['arXiv'], fn (array $pattern): bool => $pattern['type'] === 'validation' && $pattern['previous'] === $previous->pattern);

    expect(IdentifierTypePattern::findOrFail($id)->is_active)->toBeFalse();
    foreach ($replacements as $replacement) {
        $current = $type->patterns()->where('pattern', $replacement['pattern'])->firstOrFail();
        expect($current->is_active)->toBeFalse()->and($current->priority)->toBe(42);
    }
});

it('preserves an existing disabled target and merges obsolete standard duplicates safely', function (): void {
    $corrections = seedPreviousElmoPatterns();
    $type = IdentifierType::where('slug', 'DOI')->firstOrFail();
    $replacement = $corrections['DOI'][0];
    $previousId = $type->patterns()->where('type', 'validation')->value('id');
    $target = $type->patterns()->create(['type' => 'validation', 'pattern' => $replacement['pattern'], 'priority' => 77, 'is_active' => false]);

    elmoPatternCorrectionMigration()->up();

    expect(IdentifierTypePattern::find($previousId))->toBeNull()
        ->and($target->fresh()->is_active)->toBeFalse()->and($target->fresh()->priority)->toBe(77);
});

it('can reuse the old row for a remaining alternative when one split target already exists', function (): void {
    $corrections = seedPreviousElmoPatterns();
    $type = IdentifierType::where('slug', 'arXiv')->firstOrFail();
    $previous = $type->patterns()->where('type', 'validation')->where('pattern', $corrections['arXiv'][0]['previous'])->firstOrFail();
    $target = $type->patterns()->create(['type' => 'validation', 'pattern' => $corrections['arXiv'][0]['pattern'], 'priority' => 55, 'is_active' => false]);

    elmoPatternCorrectionMigration()->up();

    expect($target->fresh()->is_active)->toBeFalse()->and($target->fresh()->priority)->toBe(55)
        ->and($previous->fresh()->pattern)->toBe($corrections['arXiv'][1]['pattern']);
});

it('leaves customized patterns untouched including case-only differences under MySQL collation', function (): void {
    $corrections = seedPreviousElmoPatterns();
    $type = IdentifierType::where('slug', 'IGSN')->firstOrFail();
    $previous = $type->patterns()->where('type', 'validation')->firstOrFail();
    $custom = strtolower($corrections['IGSN'][0]['previous']);
    $previous->update(['pattern' => $custom, 'is_active' => false, 'priority' => 99]);
    $unrelated = $type->patterns()->create(['type' => 'validation', 'pattern' => '^custom-[A-Z]+$', 'priority' => 23, 'is_active' => true]);

    elmoPatternCorrectionMigration()->up();

    expect($previous->fresh()->pattern)->toBe($custom)->and($previous->fresh()->priority)->toBe(99)->and($previous->fresh()->is_active)->toBeFalse()
        ->and($unrelated->fresh()->pattern)->toBe('^custom-[A-Z]+$');
});

it('is idempotent and subsequent seeding does not recreate old patterns or change settings', function (): void {
    seedPreviousElmoPatterns();
    $migration = elmoPatternCorrectionMigration();
    $migration->up();
    $pattern = IdentifierTypePattern::where('type', 'validation')->firstOrFail();
    $pattern->update(['is_active' => false, 'priority' => 37]);
    $before = IdentifierTypePattern::orderBy('id')->get()->toArray();

    $migration->up();
    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
    $migration->up();

    foreach ($before as $row) {
        expect(IdentifierTypePattern::findOrFail($row['id'])->toArray())->toBe($row);
    }

    $afterSeeding = IdentifierTypePattern::orderBy('id')->get()->toArray();
    $this->seed(IdentifierTypePatternSeeder::class);
    $migration->down();
    expect(IdentifierTypePattern::orderBy('id')->get()->toArray())->toBe($afterSeeding);
});

it('does nothing before identifier types are seeded on a fresh installation', function (): void {
    elmoPatternCorrectionMigration()->up();
    expect(IdentifierType::count())->toBe(0)->and(IdentifierTypePattern::count())->toBe(0);

    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
    $count = IdentifierTypePattern::count();
    elmoPatternCorrectionMigration()->up();
    expect(IdentifierTypePattern::count())->toBe($count);
});
