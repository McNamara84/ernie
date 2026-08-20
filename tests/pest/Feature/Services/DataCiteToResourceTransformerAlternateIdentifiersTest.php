<?php

use App\Models\AlternateIdentifier;
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

it('imports DataCite alternate identifiers in source order without changing their types', function (): void {
    $resource = (new DataCiteToResourceTransformer)->transform([
        'attributes' => [
            'doi' => '10.5880/alternate-identifiers.001',
            'publicationYear' => 2024,
            'titles' => [['title' => 'Alternate identifier fixture']],
            'creators' => [[
                'name' => 'Doe, Jane',
                'familyName' => 'Doe',
                'givenName' => 'Jane',
                'nameType' => 'Personal',
            ]],
            'alternateIdentifiers' => [
                ['alternateIdentifier' => 'LOCAL-01', 'alternateIdentifierType' => 'Local'],
                ['alternateIdentifier' => '10273/GFLMU0020', 'alternateIdentifierType' => 'IGSN'],
            ],
        ],
    ], User::factory()->create()->id);

    expect(AlternateIdentifier::whereBelongsTo($resource)->orderBy('position')->get()->map->only(['value', 'type', 'position'])->all())
        ->toBe([
            ['value' => 'LOCAL-01', 'type' => 'Local', 'position' => 0],
            ['value' => '10273/GFLMU0020', 'type' => 'IGSN', 'position' => 1],
        ]);
});
