<?php

declare(strict_types=1);

use App\Models\RelationType;
use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;

beforeEach(function (): void {
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
    test()->seed(RelationTypeSeeder::class);

    // Ensure "Cites" relation type exists for tests
    RelationType::firstOrCreate(
        ['slug' => 'Cites'],
        ['name' => 'Cites', 'is_active' => true]
    );
});

describe('DataCiteToResourceTransformer — relatedItems', function (): void {
    it('creates RelatedItem with titles, creators, contributors and identifier', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/ri-test.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Parent Dataset']],
                'creators' => [[
                    'name' => 'Author, Alice',
                    'familyName' => 'Author',
                    'givenName' => 'Alice',
                    'nameType' => 'Personal',
                ]],
                'relatedItems' => [[
                    'relatedItemType' => 'JournalArticle',
                    'relationType' => 'Cites',
                    'relatedItemIdentifier' => [
                        'relatedItemIdentifier' => '10.1234/abcd',
                        'relatedItemIdentifierType' => 'DOI',
                        'relatedMetadataScheme' => 'citeproc-json',
                        'schemeURI' => 'https://citationstyles.org/schema',
                        'schemeType' => 'JSON',
                    ],
                    'publicationYear' => 2023,
                    'volume' => '42',
                    'issue' => '7',
                    'firstPage' => '1',
                    'lastPage' => '10',
                    'publisher' => 'Springer',
                    'edition' => '1st',
                    'titles' => [
                        ['title' => 'Cited Article'],
                        ['title' => 'Sub', 'titleType' => 'Subtitle'],
                    ],
                    'creators' => [[
                        'name' => 'Doe, Jane',
                        'givenName' => 'Jane',
                        'familyName' => 'Doe',
                        'nameType' => 'Personal',
                        'nameIdentifiers' => [[
                            'nameIdentifier' => '0000-0002-1825-0097',
                            'nameIdentifierScheme' => 'ORCID',
                        ]],
                        'affiliation' => [[
                            'name' => 'GFZ',
                            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                            'affiliationIdentifierScheme' => 'ROR',
                            'schemeUri' => 'https://ror.org',
                        ]],
                    ]],
                    'contributors' => [[
                        'contributorType' => 'Editor',
                        'name' => 'Smith, John',
                        'givenName' => 'John',
                        'familyName' => 'Smith',
                        'nameType' => 'Personal',
                    ]],
                ]],
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);
        $resource->load(['relatedItems.titles', 'relatedItems.creators.affiliations', 'relatedItems.contributors']);

        expect($resource->relatedItems)->toHaveCount(1);
        $ri = $resource->relatedItems->first();
        expect($ri->related_item_type)->toBe('JournalArticle');
        expect($ri->identifier)->toBe('10.1234/abcd');
        expect($ri->identifier_type)->toBe('DOI');
        expect($ri->related_metadata_scheme)->toBe('citeproc-json');
        expect($ri->scheme_uri)->toBe('https://citationstyles.org/schema');
        expect($ri->scheme_type)->toBe('JSON');
        expect($ri->publication_year)->toBe(2023);
        expect($ri->volume)->toBe('42');
        expect($ri->first_page)->toBe('1');
        expect($ri->last_page)->toBe('10');
        expect($ri->publisher)->toBe('Springer');
        expect($ri->titles)->toHaveCount(2);
        expect($ri->titles->first()->title)->toBe('Cited Article');
        expect($ri->titles->first()->title_type)->toBe('MainTitle');
        expect($ri->creators)->toHaveCount(1);
        $creator = $ri->creators->first();
        expect($creator->name)->toBe('Doe, Jane');
        expect($creator->name_identifier)->toBe('0000-0002-1825-0097');
        expect($creator->name_identifier_scheme)->toBe('ORCID');
        expect($creator->affiliations)->toHaveCount(1);
        expect($creator->affiliations->first()->name)->toBe('GFZ');
        expect($creator->affiliations->first()->scheme)->toBe('ROR');
        expect($creator->affiliations->first()->scheme_uri)->toBe('https://ror.org');
        expect($ri->contributors)->toHaveCount(1);
        expect($ri->contributors->first()->contributor_type)->toBe('Editor');
    });

    it('skips relatedItems with unknown relation type', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer;

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/ri-skip.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Parent']],
                'creators' => [[
                    'name' => 'Author, A.',
                    'familyName' => 'Author',
                    'givenName' => 'A.',
                    'nameType' => 'Personal',
                ]],
                'relatedItems' => [[
                    'relatedItemType' => 'JournalArticle',
                    'relationType' => 'NonExistentRelation',
                    'titles' => [['title' => 'X']],
                ]],
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);

        expect($resource->relatedItems)->toHaveCount(0);
    });
});
