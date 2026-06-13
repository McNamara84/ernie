<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
    test()->seed(RelationTypeSeeder::class);
});

it('hydrates path-only legacy DataCite GCMD subjects during resource import', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'earth-science',
            'text' => 'EARTH SCIENCE',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'biosphere',
                'text' => 'BIOSPHERE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'terrestrial-ecosystems',
                    'text' => 'TERRESTRIAL ECOSYSTEMS',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forests',
                        'text' => 'FORESTS',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $user = User::factory()->create();
    $transformer = new DataCiteToResourceTransformer;

    $resource = $transformer->transform([
        'attributes' => [
            'doi' => '10.5880/legacy-subject-path.2026.001',
            'publicationYear' => 2026,
            'titles' => [
                ['title' => 'Legacy subject path import'],
            ],
            'creators' => [
                ['familyName' => 'Importer', 'givenName' => 'Test', 'nameType' => 'Personal'],
            ],
            'subjects' => [
                [
                    'subject' => 'EARTH SCIENCE &gt; BIOSPHERE &gt; TERRESTRIAL ECOSYSTEMS &gt; FORESTS',
                    'subjectScheme' => 'NASA/GCMD Earth Science Keywords',
                ],
            ],
        ],
    ], $user->id);

    $subject = $resource->subjects()->sole();

    expect($subject->value)->toBe('EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS')
        ->and($subject->subject_scheme)->toBe('Science Keywords')
        ->and($subject->scheme_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords')
        ->and($subject->value_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/forests')
        ->and($subject->breadcrumb_path)->toBe('EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS');
});
