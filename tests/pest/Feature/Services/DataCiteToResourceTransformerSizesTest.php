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
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.bigbytes.001', ['2675059373 Bytes']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect((float) $size->numeric_value)->toBe(2_675_059_373.0)
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

        expect((float) $size->numeric_value)->toBe(1_099_511_627_776.0)
            ->and($size->unit)->toBe('Bytes');
    });

    it('still parses small decimal size strings like "1.5 GB" correctly', function (): void {
        // Guards against accidental precision regressions that could change
        // how fractional values are persisted.
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $resource = $transformer->transform(
            sizesDoiData('10.5880/sizes.small.001', ['1.5 GB']),
            $user->id,
        );

        $size = Size::where('resource_id', $resource->id)->sole();

        expect((float) $size->numeric_value)->toBe(1.5)
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

        expect((float) $size->numeric_value)->toBe(2_675_059_373.0);
    });
});
