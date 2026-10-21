<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Database\Seeders\IdentifierTypePatternSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function htmlPatternCorrectionMigration(): Migration
{
    return require database_path('migrations/2026_10_16_000002_correct_html_identifier_type_patterns.php');
}

function seedPreviousHtmlPatterns(): array
{
    $corrections = require database_path('data/identifier-patterns/2026-10-16-html-patterns.php');

    foreach ($corrections as $slug => $patterns) {
        $type = IdentifierType::create(['name' => $slug, 'slug' => $slug, 'is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
        foreach ($patterns as $pattern) {
            $type->patterns()->create(['type' => $pattern['type'], 'pattern' => $pattern['previous'], 'priority' => $pattern['previous_priority'], 'is_active' => true]);
        }
    }

    return $corrections;
}

it('backfills all HTML validators and labelled IGSN detection on an already migrated database', function (): void {
    $corrections = seedPreviousHtmlPatterns();
    $ids = IdentifierTypePattern::pluck('id')->all();

    htmlPatternCorrectionMigration()->up();

    expect(IdentifierTypePattern::pluck('id')->all())->toBe($ids);
    foreach ($corrections as $slug => $patterns) {
        $type = IdentifierType::where('slug', $slug)->firstOrFail();
        expect($type->is_active)->toBeFalse()->and($type->is_elmo_active)->toBeFalse()->and($type->is_elmo_msl_active)->toBeFalse();
        $current = $type->patterns()->sole();
        expect($current->pattern)->toBe($patterns[0]['pattern'])
            ->and($current->priority)->toBe($patterns[0]['priority'])->and($current->is_active)->toBeTrue();
    }
});

it('preserves disabled patterns and custom priorities while correcting the standard regex', function (): void {
    $corrections = seedPreviousHtmlPatterns();
    IdentifierTypePattern::query()->update(['is_active' => false, 'priority' => 42]);

    htmlPatternCorrectionMigration()->up();

    foreach ($corrections as $slug => $patterns) {
        $current = IdentifierType::where('slug', $slug)->firstOrFail()->patterns()->sole();
        expect($current->pattern)->toBe($patterns[0]['pattern'])->and($current->is_active)->toBeFalse()->and($current->priority)->toBe(42);
    }
});

it('preserves exact targets and custom previous patterns including collation-only differences', function (): void {
    $corrections = seedPreviousHtmlPatterns();
    $purl = IdentifierType::where('slug', 'PURL')->firstOrFail();
    $previousId = $purl->patterns()->sole()->id;
    $target = $purl->patterns()->create(['type' => 'validation', 'pattern' => $corrections['PURL'][0]['pattern'], 'is_active' => false, 'priority' => 77]);
    $urn = IdentifierType::where('slug', 'URN')->firstOrFail()->patterns()->sole();
    $urn->update(['pattern' => str_replace('\\S', '\\s', $urn->pattern), 'is_active' => false, 'priority' => 91]);
    $custom = $urn->toArray();

    htmlPatternCorrectionMigration()->up();

    expect(IdentifierTypePattern::find($previousId))->toBeNull()
        ->and($target->fresh()->is_active)->toBeFalse()->and($target->fresh()->priority)->toBe(77)
        ->and($urn->fresh()->toArray())->toBe($custom);
});

it('preserves original and custom target rows on a collation-only target conflict', function (string $slug): void {
    $corrections = seedPreviousHtmlPatterns();
    $type = IdentifierType::where('slug', $slug)->firstOrFail();
    $replacement = $corrections[$slug][0];
    $custom = str_replace(['\\S', '[a-z'], ['\\s', '[A-Z'], $replacement['pattern']);
    $target = $type->patterns()->create(['type' => $replacement['type'], 'pattern' => $custom, 'is_active' => false, 'priority' => 91]);
    expect(DB::table('identifier_type_patterns')->where('identifier_type_id', $type->id)
        ->where('type', $replacement['type'])->where('pattern', $replacement['pattern'])->value('id'))->toBe($target->id);
    $before = $type->patterns()->orderBy('id')->get()->toArray();

    htmlPatternCorrectionMigration()->up();

    expect($type->patterns()->orderBy('id')->get()->toArray())->toBe($before);
})->with(['LSID', 'PURL', 'RRID', 'URN', 'w3id', 'IGSN'])
    ->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires the MySQL unique-index collation.');

it('is idempotent across repeated seeding and retains corrections on rollback', function (): void {
    seedPreviousHtmlPatterns();
    $migration = htmlPatternCorrectionMigration();
    $migration->up();
    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
    $before = IdentifierTypePattern::orderBy('id')->get()->toArray();

    $migration->up();
    $this->seed(IdentifierTypePatternSeeder::class);
    $migration->down();

    expect(IdentifierTypePattern::orderBy('id')->get()->toArray())->toBe($before);
});

it('does nothing on empty or freshly seeded databases', function (): void {
    $migration = htmlPatternCorrectionMigration();
    $migration->up();
    expect(IdentifierTypePattern::count())->toBe(0);
    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
    $before = IdentifierTypePattern::orderBy('id')->get()->toArray();

    $migration->up();

    expect(IdentifierTypePattern::orderBy('id')->get()->toArray())->toBe($before);
});
