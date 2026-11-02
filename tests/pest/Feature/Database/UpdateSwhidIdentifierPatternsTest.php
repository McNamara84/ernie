<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use App\Support\Swhid;
use Database\Seeders\IdentifierTypePatternSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function swhidPatternMigration(): Migration
{
    return require database_path('migrations/2026_10_30_000001_update_swhid_identifier_patterns.php');
}

function previousSwhidPatterns(bool $active = true, ?int $priority = null): IdentifierType
{
    $type = IdentifierType::create(['name' => 'SWHID', 'slug' => 'SWHID', 'is_active' => false, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    foreach (require database_path('data/identifier-patterns/2026-10-30-swhid.php') as $group) {
        $type->patterns()->create(['type' => $group['type'], 'pattern' => $group['previous'], 'priority' => $priority ?? $group['previous_priority'], 'is_active' => $active]);
    }

    return $type;
}

it('upgrades standard rules to the same active patterns as fresh seeds and deactivates old rows', function (): void {
    $type = previousSwhidPatterns();
    $oldIds = $type->patterns()->pluck('id');
    swhidPatternMigration()->up();
    expect(IdentifierTypePattern::whereIn('id', $oldIds)->where('is_active', true)->count())->toBe(0);
    $upgraded = $type->patterns()->active()->orderBy('type')->orderBy('pattern')->get(['type', 'pattern', 'priority'])->toArray();
    expect($upgraded)->toHaveCount(3);
    $type->patterns()->delete();
    $this->seed(IdentifierTypePatternSeeder::class);
    expect($type->patterns()->active()->orderBy('type')->orderBy('pattern')->get(['type', 'pattern', 'priority'])->toArray())->toBe($upgraded)
        ->and($type->fresh()->is_active)->toBeFalse()->and($type->fresh()->is_elmo_active)->toBeFalse()->and($type->fresh()->is_elmo_msl_active)->toBeFalse();
});

it('preserves disabled rules custom priorities and already customized target rows across reruns', function (): void {
    $type = previousSwhidPatterns(false, 91);
    swhidPatternMigration()->up();
    expect($type->patterns()->where('is_active', true)->count())->toBe(0)
        ->and($type->patterns()->pluck('priority')->unique()->all())->toBe([91]);
    $target = $type->patterns()->orderByDesc('id')->firstOrFail();
    $target->update(['is_active' => true, 'priority' => 77]);
    $before = $type->patterns()->orderBy('id')->get()->toArray();
    swhidPatternMigration()->up();
    swhidPatternMigration()->down();
    $this->seed(IdentifierTypePatternSeeder::class);
    expect($type->patterns()->orderBy('id')->get()->toArray())->toBe($before);
});

it('leaves customized previous rules and collation-only target conflicts untouched', function (): void {
    $type = previousSwhidPatterns();
    $group = (require database_path('data/identifier-patterns/2026-10-30-swhid.php'))[0];
    $custom = str_replace('\\s', '\\S', $group['patterns'][0]['pattern']);
    $target = $type->patterns()->create(['type' => 'validation', 'pattern' => $custom, 'is_active' => false, 'priority' => 99]);
    if (DB::connection()->getDriverName() === 'mysql') {
        expect(DB::table('identifier_type_patterns')->where('identifier_type_id', $type->id)->where('pattern', $group['patterns'][0]['pattern'])->value('id'))->toBe($target->id);
        $before = $type->patterns()->validation()->orderBy('id')->get()->toArray();
        swhidPatternMigration()->up();
        expect($type->patterns()->validation()->orderBy('id')->get()->toArray())->toBe($before);
    }
    $type->patterns()->validation()->where('pattern', $group['previous'])->update(['pattern' => '^custom-only$']);
    $before = $type->patterns()->validation()->orderBy('id')->get()->toArray();
    swhidPatternMigration()->up();
    expect($type->patterns()->validation()->orderBy('id')->get()->toArray())->toBe($before);
});

it('is harmless on empty and already freshly seeded databases', function (): void {
    swhidPatternMigration()->up();
    expect(IdentifierTypePattern::count())->toBe(0);
    IdentifierType::create(['name' => 'SWHID', 'slug' => 'SWHID']);
    $this->seed(IdentifierTypePatternSeeder::class);
    $before = IdentifierTypePattern::orderBy('id')->get()->toArray();
    swhidPatternMigration()->up();
    expect(IdentifierTypePattern::orderBy('id')->get()->toArray())->toBe($before);
});

it('keeps all normalized valid fixtures compatible with syntax filters and detects invalid candidates', function (): void {
    $groups = require database_path('data/identifier-patterns/2026-10-30-swhid.php');
    $fixtures = json_decode(file_get_contents(base_path('tests/fixtures/identifiers/swhid-cases.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixtures['cases'] as $case) {
        $value = Swhid::normalize($case['input']);
        if ($case['valid']) {
            expect(preg_match('~'.str_replace('~', '\\~', $groups[0]['patterns'][0]['pattern']).'~u', $value))->toBe(1);
        }
        $detected = array_any($groups[1]['patterns'], fn (array $pattern): bool => preg_match('~'.str_replace('~', '\\~', $pattern['pattern']).'~u', $value) === 1);
        expect($detected)->toBe($case['candidate']);
    }
});
