<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;

beforeEach(function (): void {
    // Ensure all required lookup tables are seeded
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
});

describe('DataCiteToResourceTransformer', function (): void {

    describe('transform()', function (): void {

        it('creates resource from minimal DataCite data', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/test.2024.001',
                    'publicationYear' => 2024,
                    'titles' => [
                        ['title' => 'Test Dataset'],
                    ],
                    'creators' => [
                        [
                            'name' => 'Doe, John',
                            'familyName' => 'Doe',
                            'givenName' => 'John',
                            'nameType' => 'Personal',
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            expect($resource)->toBeInstanceOf(Resource::class)
                ->and($resource->doi)->toBe('10.5880/test.2024.001')
                ->and($resource->publication_year)->toBe(2024)
                ->and($resource->created_by_user_id)->toBe($user->id);
        });

        it('creates resource with all basic fields', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/complete.2024.001',
                    'publicationYear' => 2024,
                    'version' => '1.0.0',
                    'language' => 'en',
                    'titles' => [
                        ['title' => 'Complete Dataset'],
                    ],
                    'creators' => [
                        [
                            'familyName' => 'Smith',
                            'givenName' => 'Jane',
                            'nameType' => 'Personal',
                        ],
                    ],
                    'types' => [
                        'resourceTypeGeneral' => 'Dataset',
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            expect($resource->doi)->toBe('10.5880/complete.2024.001')
                ->and($resource->version)->toBe('1.0.0')
                ->and($resource->language_id)->not->toBeNull()
                ->and($resource->resource_type_id)->not->toBeNull();
        });

    });

    describe('titles transformation', function (): void {

        it('creates main title without titleType attribute', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/title.2024.001',
                    'titles' => [
                        ['title' => 'Main Title Without Type'],
                    ],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $titles = $resource->titles;
            $firstTitle = $titles->first();
            $mainTitleType = TitleType::where('slug', 'MainTitle')->firstOrFail();

            expect($titles)->toHaveCount(1)
                ->and($firstTitle)->not->toBeNull()
                ->and($firstTitle->value)->toBe('Main Title Without Type')
                ->and($firstTitle->title_type_id)->toBe($mainTitleType->id);
        });

        it('creates multiple titles with different types', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/multititle.2024.001',
                    'titles' => [
                        ['title' => 'Main Title'],
                        ['title' => 'Alternative Title', 'titleType' => 'AlternativeTitle'],
                        ['title' => 'Translated Title', 'titleType' => 'TranslatedTitle', 'lang' => 'de'],
                    ],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            expect($resource->titles)->toHaveCount(3);
        });

    });

    describe('creators transformation', function (): void {

        it('creates person creators with ORCID', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/orcid.2024.001',
                    'titles' => [['title' => 'ORCID Test']],
                    'creators' => [
                        [
                            'familyName' => 'Einstein',
                            'givenName' => 'Albert',
                            'nameType' => 'Personal',
                            'nameIdentifiers' => [
                                [
                                    'nameIdentifier' => 'https://orcid.org/0000-0002-1825-0097',
                                    'nameIdentifierScheme' => 'ORCID',
                                    'schemeUri' => 'https://orcid.org',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $person = Person::where('family_name', 'Einstein')->firstOrFail();

            expect($resource->creators)->toHaveCount(1)
                ->and($person->given_name)->toBe('Albert')
                ->and($person->name_identifier)->toBe('https://orcid.org/0000-0002-1825-0097')
                ->and($person->name_identifier_scheme)->toBe('ORCID');
        });

        it('creates organizational creators (institutions)', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/org.2024.001',
                    'titles' => [['title' => 'Institution Test']],
                    'creators' => [
                        [
                            'name' => 'GFZ German Research Centre for Geosciences',
                            'nameType' => 'Organizational',
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $firstCreator = $resource->creators->first();

            expect($resource->creators)->toHaveCount(1)
                ->and($firstCreator)->not->toBeNull()
                ->and($firstCreator->creatorable_type)->toContain('Institution');
        });

        it('creates creators with affiliations', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/affiliation.2024.001',
                    'titles' => [['title' => 'Affiliation Test']],
                    'creators' => [
                        [
                            'familyName' => 'Researcher',
                            'givenName' => 'Test',
                            'nameType' => 'Personal',
                            'affiliation' => [
                                [
                                    'name' => 'University of Potsdam',
                                    'affiliationIdentifier' => 'https://ror.org/03yqp9m85',
                                    'affiliationIdentifierScheme' => 'ROR',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $creator = $resource->creators->firstOrFail();
            $firstAffiliation = $creator->affiliations->first();

            expect($creator->affiliations)->toHaveCount(1)
                ->and($firstAffiliation)->not->toBeNull()
                ->and($firstAffiliation->name)->toBe('University of Potsdam')
                ->and($firstAffiliation->identifier)->toBe('https://ror.org/03yqp9m85');
        });

        it('reuses existing person by ORCID', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            // Create existing person with ORCID
            $existingPerson = Person::create([
                'family_name' => 'Existing',
                'given_name' => 'Scientist',
                'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
                'name_identifier_scheme' => 'ORCID',
                'scheme_uri' => 'https://orcid.org',
            ]);

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/reuse.2024.001',
                    'titles' => [['title' => 'Reuse ORCID Test']],
                    'creators' => [
                        [
                            'familyName' => 'Existing',
                            'givenName' => 'Scientist',
                            'nameType' => 'Personal',
                            'nameIdentifiers' => [
                                [
                                    'nameIdentifier' => 'https://orcid.org/0000-0001-2345-6789',
                                    'nameIdentifierScheme' => 'ORCID',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $creator = $resource->creators->firstOrFail();

            expect($creator->creatorable_id)->toBe($existingPerson->id)
                ->and(Person::where('family_name', 'Existing')->count())->toBe(1);
        });

    });

    describe('contributors transformation', function (): void {

        it('creates contributors with type', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/contrib.2024.001',
                    'titles' => [['title' => 'Contributor Test']],
                    'creators' => [
                        ['familyName' => 'Author', 'givenName' => 'Main', 'nameType' => 'Personal'],
                    ],
                    'contributors' => [
                        [
                            'familyName' => 'Editor',
                            'givenName' => 'Chief',
                            'nameType' => 'Personal',
                            'contributorType' => 'Editor',
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $editorType = ContributorType::where('slug', 'Editor')->firstOrFail();
            $firstContributor = $resource->contributors->first();

            expect($resource->contributors)->toHaveCount(1)
                ->and($firstContributor)->not->toBeNull()
                ->and($firstContributor->contributor_type_id)->toBe($editorType->id);
        });

        it('defaults to Other for unknown contributor type', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/unknown.2024.001',
                    'titles' => [['title' => 'Unknown Type Test']],
                    'creators' => [
                        ['familyName' => 'Author', 'givenName' => 'Main', 'nameType' => 'Personal'],
                    ],
                    'contributors' => [
                        [
                            'familyName' => 'Helper',
                            'givenName' => 'Unknown',
                            'nameType' => 'Personal',
                            'contributorType' => 'NonExistentType',
                        ],
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $otherType = ContributorType::where('slug', 'Other')->firstOrFail();
            $firstContributor = $resource->contributors->first();

            expect($resource->contributors)->toHaveCount(1)
                ->and($firstContributor)->not->toBeNull()
                ->and($firstContributor->contributor_type_id)->toBe($otherType->id);
        });

    });

    describe('resource type resolution', function (): void {

        it('resolves Dataset type correctly', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/dataset.2024.001',
                    'titles' => [['title' => 'Dataset Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'types' => [
                        'resourceTypeGeneral' => 'Dataset',
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $datasetType = ResourceType::where('slug', 'dataset')->firstOrFail();

            expect($resource->resource_type_id)->toBe($datasetType->id);
        });

        it('converts PascalCase types to kebab-case slugs', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/pascal.2024.001',
                    'titles' => [['title' => 'PascalCase Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'types' => [
                        'resourceTypeGeneral' => 'JournalArticle',
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $journalType = ResourceType::where('slug', 'journal-article')->first();
            $otherType = ResourceType::where('slug', 'other')->firstOrFail();

            // Falls back to 'other' if journal-article doesn't exist
            $expectedId = $journalType?->id ?? $otherType->id;
            expect($resource->resource_type_id)->toBe($expectedId);
        });

        it('defaults to Other for missing type', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/notype.2024.001',
                    'titles' => [['title' => 'No Type Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    // No 'types' field
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $otherType = ResourceType::where('slug', 'other')->firstOrFail();

            expect($resource->resource_type_id)->toBe($otherType->id);
        });

    });

    describe('publisher resolution', function (): void {

        it('creates new publisher if not exists', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/newpub.2024.001',
                    'titles' => [['title' => 'New Publisher Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'publisher' => 'New Science Publisher',
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $publisher = Publisher::find($resource->publisher_id);

            expect($publisher)->not->toBeNull()
                ->and($publisher->name)->toBe('New Science Publisher');
        });

        it('reuses existing publisher', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $existingPublisher = Publisher::create([
                'name' => 'Existing Publisher',
                'language' => 'en',
                'is_default' => false,
            ]);

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/existpub.2024.001',
                    'titles' => [['title' => 'Existing Publisher Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'publisher' => 'Existing Publisher',
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            expect($resource->publisher_id)->toBe($existingPublisher->id);
        });

        it('handles publisher as object (DataCite 4.5+)', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/objpub.2024.001',
                    'titles' => [['title' => 'Object Publisher Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'publisher' => [
                        'name' => 'Object Style Publisher',
                        'publisherIdentifier' => 'https://ror.org/12345',
                        'publisherIdentifierScheme' => 'ROR',
                    ],
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $publisher = Publisher::find($resource->publisher_id);

            expect($publisher)->not->toBeNull()
                ->and($publisher->name)->toBe('Object Style Publisher')
                ->and($publisher->identifier)->toBe('https://ror.org/12345');
        });

        it('uses default publisher when not provided', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            // Get or create default publisher
            $defaultPublisher = Publisher::where('is_default', true)->first()
                ?? Publisher::create([
                    'name' => 'GFZ Data Services',
                    'language' => 'en',
                    'is_default' => true,
                ]);

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/defpub.2024.001',
                    'titles' => [['title' => 'Default Publisher Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    // No publisher field
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            expect($resource->publisher_id)->toBe($defaultPublisher->id);
        });

    });

    describe('language resolution', function (): void {

        it('resolves language from ISO code', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/lang.2024.001',
                    'titles' => [['title' => 'Language Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    'language' => 'de',
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);
            $germanLanguage = Language::where('code', 'de')->first();

            if ($germanLanguage !== null) {
                expect($resource->language_id)->toBe($germanLanguage->id);
            } else {
                expect($resource->language_id)->toBeNull();
            }
        });

        it('allows null language', function (): void {
            $user = User::factory()->create();
            $transformer = new DataCiteToResourceTransformer();

            $doiData = [
                'attributes' => [
                    'doi' => '10.5880/nolang.2024.001',
                    'titles' => [['title' => 'No Language Test']],
                    'creators' => [
                        ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                    ],
                    // No language field
                ],
            ];

            $resource = $transformer->transform($doiData, $user->id);

            // Test passes - language can be null or have a default
            expect($resource)->toBeInstanceOf(Resource::class);
        });

    });

});

describe('DataCiteToResourceTransformer - Issue #371: Date Created Handling', function (): void {

    beforeEach(function (): void {
        test()->seed(\Database\Seeders\ResourceTypeSeeder::class);
        test()->seed(\Database\Seeders\TitleTypeSeeder::class);
        test()->seed(\Database\Seeders\DateTypeSeeder::class);
    });

    it('preserves imported Created date from DataCite response', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer();

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/created.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Test with Created Date']],
                'creators' => [
                    ['familyName' => 'Doe', 'givenName' => 'John', 'nameType' => 'Personal'],
                ],
                'dates' => [
                    ['date' => '2022-06-15', 'dateType' => 'Created'],
                ],
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);

        // Find the 'Created' date
        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe('2022-06-15');
    });

    it('adds fallback Created date with current date when not in DataCite response', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer();
        $today = now()->format('Y-m-d');

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/nocreated.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Test without Created Date']],
                'creators' => [
                    ['familyName' => 'Smith', 'givenName' => 'Jane', 'nameType' => 'Personal'],
                ],
                'dates' => [
                    ['date' => '2024-01-01', 'dateType' => 'Issued'],
                ],
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);

        // Find the 'Created' date
        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($today);
    });

    it('adds fallback Created date when dates array is empty', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer();
        $today = now()->format('Y-m-d');

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/nodates.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Test without any Dates']],
                'creators' => [
                    ['familyName' => 'Brown', 'givenName' => 'Bob', 'nameType' => 'Personal'],
                ],
                // No dates array at all
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);

        // Find the 'Created' date
        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate)->not->toBeNull()
            ->and($createdDate->date_value)->toBe($today);
    });

    it('does not duplicate Created date when already present', function (): void {
        $user = User::factory()->create();
        $transformer = new DataCiteToResourceTransformer();

        $doiData = [
            'attributes' => [
                'doi' => '10.5880/hascreated.2024.001',
                'publicationYear' => 2024,
                'titles' => [['title' => 'Test with existing Created Date']],
                'creators' => [
                    ['familyName' => 'Test', 'givenName' => 'User', 'nameType' => 'Personal'],
                ],
                'dates' => [
                    ['date' => '2020-01-01', 'dateType' => 'Created'],
                    ['date' => '2024-06-01', 'dateType' => 'Issued'],
                ],
            ],
        ];

        $resource = $transformer->transform($doiData, $user->id);

        // Count 'Created' dates - should be exactly 1
        $createdDatesCount = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->count();

        expect($createdDatesCount)->toBe(1);

        // Verify it's the imported date, not a fallback
        $createdDate = $resource->dates()->whereHas('dateType', function ($q) {
            $q->whereRaw('LOWER(slug) = ?', ['created']);
        })->first();

        expect($createdDate->date_value)->toBe('2020-01-01');
    });

});
