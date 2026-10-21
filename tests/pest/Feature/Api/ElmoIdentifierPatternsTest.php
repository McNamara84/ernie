<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Database\Seeders\IdentifierTypePatternSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);
    Http::preventStrayRequests();
    $this->seed([IdentifierTypeSeeder::class, IdentifierTypePatternSeeder::class]);
});

it('validates complete issue inputs after decoding the real response exactly once', function (): void {
    $response = $this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk();
    $types = collect(json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR))->keyBy('slug');
    $cases = json_decode(file_get_contents(base_path('tests/pest/Fixtures/Identifiers/identifier-pattern-cases.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($cases as $case) {
        $type = $types[$case['type']];
        $message = json_encode($case, JSON_THROW_ON_ERROR);
        $matches = fn (string $pattern): bool => preg_match('~'.str_replace('~', '\\~', $pattern).'~i', $case['input']) === 1;

        $this->assertSame($case['valid'], $matches($type['pattern']), $message);
        $this->assertSame($case['valid'], array_any($type['patterns']['validation'], fn (array $pattern): bool => $matches($pattern['pattern'])), $message);
    }

    Http::assertNothingSent();
});

it('ranks specific namespaces above DOI and ISBN above EAN13 without narrowing validation', function (): void {
    $types = collect($this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->json())->keyBy('slug');
    $examples = [
        ['10.60510/gf1211s-47', 'IGSN', 'DOI'],
        ['DOI:10.60510/gf1211s-47', 'IGSN', 'DOI'],
        ['doi: 10.60510/gf1211s-47', 'IGSN', 'DOI'],
        ['https://doi.org/10.60510/gf1211s-47', 'IGSN', 'DOI'],
        ['10.58006/sample123', 'IGSN', 'DOI'],
        ['https://doi.org/10.82210/162fda4d', 'RAiD', 'DOI'],
        ['10.82210/162fda4d', 'RAiD', 'DOI'],
        ['9783831048069', 'ISBN', 'EAN13'],
    ];

    foreach ($examples as [$input, $specific, $general]) {
        $priority = function (string $slug) use ($types, $input): int {
            return collect($types[$slug]['patterns']['detection'])
                ->filter(fn (array $pattern): bool => preg_match('~'.str_replace('~', '\\~', $pattern['pattern']).'~i', $input) === 1)
                ->max('priority') ?? -1;
        };

        expect($priority($specific))->toBeGreaterThan($priority($general));
        $this->assertSame(1, preg_match('~'.$types[$general]['pattern'].'~i', $input));
    }

    foreach (['IGSN', 'RAiD'] as $slug) {
        foreach ($types[$slug]['patterns']['detection'] as $pattern) {
            foreach (['10.99999/unknown', 'DOI:10.99999/unknown'] as $unknown) {
                expect(preg_match('~'.$pattern['pattern'].'~i', $unknown))->toBe(0);
            }
        }
    }
});

it('does not detect incomplete labels or unrelated pages as a specific identifier', function (string $slug, string $input): void {
    $type = collect($this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->json())->firstWhere('slug', $slug);

    foreach ($type['patterns']['detection'] as $pattern) {
        expect(preg_match('~'.$pattern['pattern'].'~i', $input))->toBe(0);
    }
})->with([
    ['arXiv', 'arXiv:'], ['arXiv', 'https://arxiv.org/help'],
    ['RAiD', 'https://raid.org/about'], ['RAiD', 'https://raid.org/'],
    ['ISBN', 'ISBN:'], ['ISBN', 'urn:isbn:'], ['EAN13', 'urn:ean13:'],
    ['IGSN', '10.273/ABC123'], ['IGSN', 'https://igsn.org/10.273/ABC123'],
    ['DOI', 'doi:'], ['DOI', '10.1234'],
]);

it('exposes advisory checksum algorithms without claiming regexes verify the check digit', function (): void {
    $types = collect($this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->json())->keyBy('slug');

    expect($types['ISBN']['checksum'])->toBe(['algorithms' => ['isbn10-mod11', 'isbn13-mod10'], 'mode' => 'advisory'])
        ->and($types['EAN13']['checksum'])->toBe(['algorithms' => ['gtin13-mod10'], 'mode' => 'advisory'])
        ->and($types['DOI'])->not->toHaveKey('checksum');

    foreach (['ISBN', 'EAN13'] as $slug) {
        expect(preg_match('~'.$types[$slug]['pattern'].'~', '9783831048068'))->toBe(1);
    }
});

it('never emits a match-all regex for missing, disabled or empty validation patterns', function (): void {
    IdentifierTypePattern::query()->delete();
    $doi = IdentifierType::where('slug', 'DOI')->firstOrFail();
    $doi->patterns()->createMany([
        ['type' => 'validation', 'pattern' => '', 'priority' => 10, 'is_active' => true],
        ['type' => 'validation', 'pattern' => '^disabled$', 'priority' => 99, 'is_active' => false],
        ['type' => 'detection', 'pattern' => '^doi:', 'priority' => 20, 'is_active' => true],
    ]);

    foreach ($this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->json() as $type) {
        expect($type['pattern'])->toBe('(?!)')
            ->and(preg_match('~'.$type['pattern'].'~', ''))->toBe(0)
            ->and(preg_match('~'.$type['pattern'].'~', 'anything'))->toBe(0);
    }
});

it('combines all active validation alternatives deterministically and rejects partial matches', function (): void {
    IdentifierTypePattern::query()->delete();
    $doi = IdentifierType::where('slug', 'DOI')->firstOrFail();
    $doi->patterns()->createMany([
        ['type' => 'validation', 'pattern' => 'first', 'priority' => 10, 'is_active' => true],
        ['type' => 'validation', 'pattern' => '^second$', 'priority' => 10, 'is_active' => true],
        ['type' => 'validation', 'pattern' => '^disabled$', 'priority' => 100, 'is_active' => false],
        ['type' => 'detection', 'pattern' => 'detection-only', 'priority' => 100, 'is_active' => true],
    ]);
    $type = collect($this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->json())->firstWhere('slug', 'DOI');

    expect(array_column($type['patterns']['validation'], 'pattern'))->toBe(['first', '^second$']);

    foreach (['first', 'second'] as $input) {
        expect(preg_match('~'.$type['pattern'].'~', $input))->toBe(1);
    }

    foreach (['first suffix', 'prefixfirst', "second\n", 'disabled', 'detection-only'] as $input) {
        expect(preg_match('~'.$type['pattern'].'~', $input))->toBe(0);
    }
});

it('keeps the additional fields exclusive to ELMO and respects editor activation', function (): void {
    $isbn = IdentifierType::where('slug', 'ISBN')->firstOrFail();
    $isbn->update(['is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => true]);
    $this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->assertJsonFragment(['slug' => 'ISBN']);

    foreach (['/api/v1/identifier-types', '/api/v1/identifier-types/ernie', '/api/v1/identifier-types/elmo-msl'] as $url) {
        foreach ($this->getJson($url, ['X-API-Key' => 'test-api-key'])->assertOk()->json() as $type) {
            expect($type)->not->toHaveKeys(['pattern', 'checksum']);
        }
    }

    $isbn->update(['is_active' => true, 'is_elmo_active' => false]);
    $this->getJson('/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key'])->assertOk()->assertJsonMissing(['slug' => 'ISBN']);
});
