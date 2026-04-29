<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Size;
use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;

beforeEach(function (): void {
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
});

/**
 * Build a minimal DataCite payload so we can exercise transformSizes() via the
 * public transform() entry point without tripping over unrelated required data.
 *
 * @param  array<int, string>  $sizes
 * @return array<string, mixed>
 */
function sizesDoiData(string $doi, array $sizes): array
{
    return [
        'attributes' => [
            'doi' => $doi,
            'publicationYear' => 2024,
            'titles' => [
                ['title' => 'Sizes Regression Fixture'],
            ],
            'creators' => [
                [
                    'name' => 'Doe, John',
                    'familyName' => 'Doe',
                    'givenName' => 'John',
                    'nameType' => 'Personal',
                ],
            ],
            'sizes' => $sizes,
        ],
    ];
}

describe('DataCiteToResourceTransformer::transformSizes()', function (): void {

    it('stores byte values exceeding the old decimal(12, 4) limit without overflow', function (): void {
        // Regression guard for SQLSTATE[22003] "Numeric value out of range" that
        // aborted the DataCite import job when a DOI reported file sizes beyond
        // ~99 MB (the decimal(12, 4) ceiling). With decimal(20, 4) these values
        // must persist cleanly.
        //
        // We assert the exact string produced by Eloquent's `decimal:4` cast
        // (rather than casting to float) so that BOTH the numeric value AND
        // the 4-decimal scale are verified. A float-based comparison would not
        // notice accidental scale regressions (e.g. a future `decimal:2` cast).
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.bigbytes.001', ['2675059373 Bytes']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBe('2675059373.0000')
            ->and($size->unit)->toBe('Bytes')
            ->and($size->type)->toBeNull();
    });

    it('stores multi-terabyte byte values without overflow', function (): void {
        // Push well past the decimal(12, 4) ceiling to make sure the widened
        // precision holds for realistically large dataset payloads, not just
        // the specific value from the original bug report.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.bigbytes.002', ['1099511627776 Bytes']), // 1 TiB
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBe('1099511627776.0000')
            ->and($size->unit)->toBe('Bytes');
    });

    it('still parses small decimal size strings like "1.5 GB" correctly', function (): void {
        // Guards against accidental precision regressions that could change
        // how fractional values are persisted. Asserting the full scaled
        // string ("1.5000") also confirms the `decimal:4` cast is intact.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.small.001', ['1.5 GB']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBe('1.5000')
            ->and($size->unit)->toBe('GB');
    });

    it('falls back to storing unparseable size strings in the unit column', function (): void {
        // Preserves the existing transformer contract: values that do not match
        // the "number unit" pattern must remain retrievable via export_string.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.freetext.001', ['several pages']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBeNull()
            ->and($size->unit)->toBe('several pages');
    });

    it('preserves long DataCite free-text size descriptions verbatim in unit', function (): void {
        // Regression guard for SQLSTATE[22001] "Data too long for column 'unit'"
        // observed when importing real DataCite payloads that use the `sizes`
        // property as a prose summary rather than a structured value.
        // The full original wording must survive the round-trip so it can be
        // re-exported unchanged via the Size::$export_string accessor.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;
        $original = 'Approximately 80 active stations; greater than 440MB/day.';

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.freetext.long.001', [$original]),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBeNull()
            ->and($size->unit)->toBe($original)
            ->and($size->export_string)->toBe($original);
    });

    it('does not split when the trailing unit token is a long sentence fragment', function (): void {
        // The tail "files of seismic data, version 2" starts with a real word
        // but is far longer than a unit and contains a comma. Splitting would
        // (a) lose the original wording on export and (b) cram the remainder
        // into `sizes.unit`. The transformer must therefore keep the whole
        // string in `unit` and leave `numeric_value` empty.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;
        $original = '1000 files of seismic data, version 2';

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.freetext.long.002', [$original]),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBeNull()
            ->and($size->unit)->toBe($original);
    });

    it('does not split when the trailing unit token contains semicolons', function (): void {
        // Sentence punctuation is a strong signal that the tail is prose, not
        // a unit. The transformer must keep the original string intact.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;
        $original = '440MB/day; about 80 active stations';

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.freetext.long.003', [$original]),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBeNull()
            ->and($size->unit)->toBe($original);
    });

    it('still splits compact unit tokens with up to three words', function (): void {
        // Boundary case for the "<= 3 words" rule of looksLikeSizeUnit():
        // "files per minute" is exactly three tokens, contains no punctuation,
        // and stays well below the 50-character cap, so it must still be
        // recognised as a unit and trigger the structured split.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.boundary.threewords', ['12 files per minute']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect($size->numeric_value)->toBe('12.0000')
            ->and($size->unit)->toBe('files per minute');
    });

    it('skips empty size entries without persisting placeholder rows', function (): void {
        // Empty strings must be silently ignored — they contain no information
        // worth round-tripping and would otherwise produce blank Size rows.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.empty.001', ['', '5 GB']),
            $user->id,
        );

        $sizes = Size::where('resource_id', $resource->id)->get();

        expect($sizes)->toHaveCount(1)
            ->and($sizes->first()->numeric_value)->toBe('5.0000')
            ->and($sizes->first()->unit)->toBe('GB');
    });
});

describe('sizes.numeric_value column precision', function (): void {

    it('allows values well beyond the legacy decimal(12, 4) ceiling', function (): void {
        // Schema-level regression test. Writes directly through the Eloquent
        // model (bypassing the transformer) so any future migration that
        // narrows the column is caught here immediately.
        $resource = Resource::factory()->create();

        $size = Size::create([
            'resource_id' => $resource->id,
            'numeric_value' => '2675059373',
            'unit' => 'Bytes',
        ]);

        $size->refresh();

        expect($size->numeric_value)->toBe('2675059373.0000');
    });
});
