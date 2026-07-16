<?php

declare(strict_types=1);

use App\Exceptions\JsonValidationException;
use App\Services\JsonSchemaValidator;

describe('JsonSchemaValidator', function () {
    describe('validate()', function () {
        it('returns true for valid minimal DataCite JSON', function () {
            $validator = new JsonSchemaValidator;

            $validData = [
                'identifiers' => [['identifier' => '10.5880/test.2026.001', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            expect($validator->validate($validData))->toBeTrue();
        });

        it('throws JsonValidationException for missing required fields', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                // Missing: creators, titles, publisher, publicationYear, types
            ];

            $validator->validate($invalidData);
        })->throws(JsonValidationException::class);

        it('throws JsonValidationException with correct schema version', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
            ];

            try {
                $validator->validate($invalidData);
            } catch (JsonValidationException $e) {
                expect($e->getSchemaVersion())->toBe('4.7');
            }
        });

        it('throws JsonValidationException with errors array', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
            ];

            try {
                $validator->validate($invalidData);
            } catch (JsonValidationException $e) {
                expect($e->getErrors())->toBeArray();
                expect($e->getErrors())->not->toBeEmpty();

                // Each error should have required structure
                $firstError = $e->getErrors()[0];
                expect($firstError)->toHaveKey('path');
                expect($firstError)->toHaveKey('message');
                expect($firstError)->toHaveKey('keyword');
                expect($firstError)->toHaveKey('context');
            }
        });

        it('validates resourceTypeGeneral values added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $newResourceTypes = [
                'Award',
                'Book',
                'BookChapter',
                'ComputationalNotebook',
                'ConferencePaper',
                'ConferenceProceeding',
                'Dissertation',
                'Instrument',
                'Journal',
                'JournalArticle',
                'OutputManagementPlan',
                'PeerReview',
                'Preprint',
                'Project',
                'Report',
                'Standard',
                'StudyRegistration',
            ];

            foreach ($newResourceTypes as $resourceType) {
                $data = [
                    'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                    'creators' => [['name' => 'Test Author']],
                    'titles' => [['title' => 'Test Title']],
                    'publisher' => 'Test Publisher',
                    'publicationYear' => '2026',
                    'types' => ['resourceType' => $resourceType, 'resourceTypeGeneral' => $resourceType],
                ];

                expect($validator->validate($data))->toBeTrue("Expected {$resourceType} to be valid");
            }
        });

        it('validates contributorType Translator added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'contributors' => [
                    ['name' => 'Translator Person', 'contributorType' => 'Translator'],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates relationType values added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $newRelationTypes = [
                'IsPublishedIn',
                'IsCollectedBy',
                'Collects',
                'IsTranslationOf',
                'HasTranslation',
            ];

            foreach ($newRelationTypes as $relationType) {
                $data = [
                    'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                    'creators' => [['name' => 'Test Author']],
                    'titles' => [['title' => 'Test Title']],
                    'publisher' => 'Test Publisher',
                    'publicationYear' => '2026',
                    'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                    'relatedIdentifiers' => [
                        [
                            'relatedIdentifier' => '10.5880/other',
                            'relatedIdentifierType' => 'DOI',
                            'relationType' => $relationType,
                        ],
                    ],
                ];

                expect($validator->validate($data))->toBeTrue("Expected {$relationType} to be valid");
            }
        });

        it('validates relatedIdentifierType CSTR added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => 'CSTR:12345',
                        'relatedIdentifierType' => 'CSTR',
                        'relationType' => 'References',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates relatedIdentifierType RRID added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => 'RRID:AB_12345',
                        'relatedIdentifierType' => 'RRID',
                        'relationType' => 'References',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });
        it('validates new 4.7 relatedIdentifierType RAiD', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => 'https://raid.org/10.25.1/abc123',
                        'relatedIdentifierType' => 'RAiD',
                        'relationType' => 'References',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.7 relatedIdentifierType SWHID', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => 'swh:1:cnt:94a9ed024d3859793618152ea559a168bbcbb5e2',
                        'relatedIdentifierType' => 'SWHID',
                        'relationType' => 'References',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.7 relationType Other with relationTypeInformation', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => '10.5880/other',
                        'relatedIdentifierType' => 'DOI',
                        'relationType' => 'Other',
                        'relationTypeInformation' => 'Custom relation description',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.7 resourceTypeGeneral Poster', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Poster', 'resourceTypeGeneral' => 'Poster'],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.7 resourceTypeGeneral Presentation', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Presentation', 'resourceTypeGeneral' => 'Presentation'],
            ];

            expect($validator->validate($data))->toBeTrue();
        });
        it('validates dateType Coverage added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'dates' => [
                    ['date' => '2020-01-01/2025-12-31', 'dateType' => 'Coverage'],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates publisher with object structure added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => [
                    'name' => 'GFZ Data Services',
                    'publisherIdentifier' => 'https://ror.org/04z8jg394',
                    'publisherIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org/',
                ],
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates subjects with classificationCode added in DataCite 4.6', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'subjects' => [
                    [
                        'subject' => 'Geology',
                        'subjectScheme' => 'GCMD',
                        'classificationCode' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS/CRYSTALS',
                    ],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('rejects invalid resourceTypeGeneral values', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'InvalidType', 'resourceTypeGeneral' => 'InvalidType'],
            ];

            $validator->validate($data);
        })->throws(JsonValidationException::class);

        it('rejects invalid contributorType values', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'contributors' => [
                    ['name' => 'Test', 'contributorType' => 'InvalidContributorType'],
                ],
            ];

            $validator->validate($data);
        })->throws(JsonValidationException::class);

        it('validates complete resource with all optional fields', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test.2026.001', 'identifierType' => 'DOI']],
                'creators' => [
                    [
                        'name' => 'Doe, John',
                        'nameType' => 'Personal',
                        'givenName' => 'John',
                        'familyName' => 'Doe',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => '0000-0001-2345-6789', 'nameIdentifierScheme' => 'ORCID'],
                        ],
                        'affiliation' => [
                            ['name' => 'GFZ Helmholtz Centre Potsdam'],
                        ],
                    ],
                ],
                'titles' => [
                    ['title' => 'Main Title'],
                    ['title' => 'Subtitle', 'titleType' => 'Subtitle'],
                ],
                'publisher' => 'GFZ Data Services',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Seismic Data', 'resourceTypeGeneral' => 'Dataset'],
                'subjects' => [['subject' => 'Seismology']],
                'contributors' => [['name' => 'Smith, Jane', 'contributorType' => 'DataCurator']],
                'dates' => [['date' => '2026-01-15', 'dateType' => 'Created']],
                'language' => 'en',
                'alternateIdentifiers' => [
                    ['alternateIdentifier' => 'ABC123', 'alternateIdentifierType' => 'Internal ID'],
                ],
                'relatedIdentifiers' => [
                    [
                        'relatedIdentifier' => '10.5880/other.2025.001',
                        'relatedIdentifierType' => 'DOI',
                        'relationType' => 'Cites',
                    ],
                ],
                'sizes' => ['1 GB'],
                'formats' => ['application/x-hdf5'],
                'version' => '1.0',
                'rightsList' => [
                    ['rights' => 'CC BY 4.0', 'rightsUri' => 'https://creativecommons.org/licenses/by/4.0/'],
                ],
                'descriptions' => [
                    ['description' => 'This dataset contains...', 'descriptionType' => 'Abstract'],
                ],
                'geoLocations' => [
                    [
                        'geoLocationPlace' => 'Potsdam, Germany',
                        'geoLocationPoint' => ['pointLongitude' => 13.0, 'pointLatitude' => 52.4],
                    ],
                ],
                'fundingReferences' => [
                    ['funderName' => 'DFG', 'awardNumber' => '12345'],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });
    });

    describe('isValid()', function () {
        it('returns true for valid data', function () {
            $validator = new JsonSchemaValidator;

            $validData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            expect($validator->isValid($validData))->toBeTrue();
        });

        it('returns false for invalid data and populates errors', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                // Missing required fields
            ];

            $errors = null;
            $result = $validator->isValid($invalidData, $errors);

            expect($result)->toBeFalse();
            expect($errors)->toBeArray();
            expect($errors)->not->toBeEmpty();
        });

        it('does not throw exception for invalid data', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = ['invalid' => 'data'];

            $errors = null;
            $result = $validator->isValid($invalidData, $errors);

            expect($result)->toBeFalse();
        });
    });

    describe('strictMode', function () {
        it('allows data without identifiers in non-strict mode (default)', function () {
            $validator = new JsonSchemaValidator;

            // Valid data without identifiers - allowed for draft exports
            $data = [
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            // Non-strict mode (default) - should pass
            expect($validator->validate($data, strictMode: false))->toBeTrue();
        });

        it('rejects data without identifiers in strict mode', function () {
            $validator = new JsonSchemaValidator;

            // Data without identifiers - not allowed for registration
            $data = [
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            // Strict mode - should fail because a DOI is required
            expect(fn () => $validator->validate($data, strictMode: true))
                ->toThrow(JsonValidationException::class);
        });

        it('passes with doi in strict mode', function () {
            $validator = new JsonSchemaValidator;

            // Data with DOI - valid for registration
            $data = [
                'doi' => '10.5880/test',
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            // Strict mode with DOI - should pass
            expect($validator->validate($data, strictMode: true))->toBeTrue();
        });

        it('isValid respects strictMode parameter', function () {
            $validator = new JsonSchemaValidator;

            $dataWithoutDoi = [
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
            ];

            $errors = null;

            // Non-strict mode - should be valid
            expect($validator->isValid($dataWithoutDoi, $errors, strictMode: false))->toBeTrue();

            // Strict mode - should be invalid
            expect($validator->isValid($dataWithoutDoi, $errors, strictMode: true))->toBeFalse();
            expect($errors)->not->toBeEmpty();
            expect(collect($errors)->pluck('path')->toArray())->toContain('/doi');
        });
    });
});

describe('DataCite Draft 2020-12 contract', function () {
    it('declares the 2020-12 dialect and only uses executable formats', function () {
        $schema = json_decode(
            (string) file_get_contents(base_path('resources/data/scheme/datacite_4.7_schema.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $formats = [];
        $collectFormats = function (mixed $node) use (&$collectFormats, &$formats): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['format']) && is_string($node['format'])) {
                $formats[] = $node['format'];
            }

            foreach ($node as $value) {
                $collectFormats($value);
            }
        };
        $collectFormats($schema);

        expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
            ->and($schema['$id'])->toBe('urn:ernie:schema:datacite:4.7')
            ->and($schema)->toHaveKey('$defs')
            ->and(array_values(array_unique($formats)))->toEqualCanonicalizing([
                'uri',
                'datacite-year',
                'datacite-date',
            ]);
    });

    it('rejects unknown properties recursively', function () {
        $base = [
            'creators' => [[
                'name' => 'Test Author',
                'nameIdentifiers' => [[
                    'nameIdentifier' => '0000-0001-2345-6789',
                    'nameIdentifierScheme' => 'ORCID',
                ]],
                'affiliation' => [['name' => 'GFZ']],
            ]],
            'titles' => [['title' => 'Test Title']],
            'publisher' => ['name' => 'Test Publisher'],
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
            'relatedIdentifiers' => [[
                'relatedIdentifier' => '10.5880/related',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'References',
            ]],
            'geoLocations' => [[
                'geoLocationPoint' => ['pointLongitude' => 13.0, 'pointLatitude' => 52.4],
            ]],
            'fundingReferences' => [['funderName' => 'DFG']],
            'relatedItems' => [[
                'relatedItemType' => 'JournalArticle',
                'relationType' => 'References',
                'titles' => [['title' => 'Related title']],
            ]],
        ];

        $mutations = [
            'root' => fn (array $data): array => $data + ['unexpected' => true],
            'creator' => function (array $data): array {
                $data['creators'][0]['unexpected'] = true;

                return $data;
            },
            'name identifier' => function (array $data): array {
                $data['creators'][0]['nameIdentifiers'][0]['unexpected'] = true;

                return $data;
            },
            'affiliation' => function (array $data): array {
                $data['creators'][0]['affiliation'][0]['unexpected'] = true;

                return $data;
            },
            'related identifier' => function (array $data): array {
                $data['relatedIdentifiers'][0]['unexpected'] = true;

                return $data;
            },
            'geo point' => function (array $data): array {
                $data['geoLocations'][0]['geoLocationPoint']['unexpected'] = true;

                return $data;
            },
            'funding reference' => function (array $data): array {
                $data['fundingReferences'][0]['unexpected'] = true;

                return $data;
            },
            'related item title' => function (array $data): array {
                $data['relatedItems'][0]['titles'][0]['unexpected'] = true;

                return $data;
            },
        ];

        $validator = new JsonSchemaValidator;
        foreach ($mutations as $label => $mutate) {
            $errors = null;
            expect($validator->isValid($mutate($base), $errors))->toBeFalse($label)
                ->and($errors)->not->toBeEmpty()
                ->and(collect($errors)->pluck('keyword')->all())->toContain('unevaluatedProperties')
                ->and(collect($errors)->pluck('message')->contains(
                    fn (string $message): bool => str_contains($message, "'unexpected'"),
                ))->toBeTrue();
        }
    });

    it('allows metadata scheme fields only for metadata relations', function () {
        $base = [
            'creators' => [['name' => 'Test Author']],
            'titles' => [['title' => 'Test Title']],
            'publisher' => 'Test Publisher',
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
        ];
        $metadata = [
            'relatedIdentifier' => 'https://example.org/schema.xml',
            'relatedIdentifierType' => 'URL',
            'relationType' => 'HasMetadata',
            'relatedMetadataScheme' => 'Example',
            'schemeUri' => 'https://example.org/schema',
            'schemeType' => 'XSD',
        ];

        $validator = new JsonSchemaValidator;
        expect($validator->validate($base + ['relatedIdentifiers' => [$metadata]]))->toBeTrue();

        $metadata['relationType'] = 'References';
        expect($validator->isValid($base + ['relatedIdentifiers' => [$metadata]]))->toBeFalse();
    });

    it('asserts URI formats without mutating values', function () {
        $base = [
            'creators' => [['name' => 'Test Author']],
            'titles' => [['title' => 'Test Title']],
            'publisher' => 'Test Publisher',
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
        ];
        $validator = new JsonSchemaValidator;

        expect($validator->validate($base + [
            'subjects' => [['subject' => 'Geology', 'schemeUri' => 'https://example.org/scheme']],
            'relatedIdentifiers' => [[
                'relatedIdentifier' => 'urn:isbn:9780141036144',
                'relatedIdentifierType' => 'URN',
                'relationType' => 'References',
            ]],
        ]))->toBeTrue();

        $errors = null;
        expect($validator->isValid($base + [
            'subjects' => [['subject' => 'Geology', 'schemeUri' => 'example.org/scheme']],
        ], $errors))->toBeFalse()
            ->and(collect($errors)->pluck('path')->all())->toContain('/subjects/0/schemeUri')
            ->and(collect($errors)->pluck('keyword')->all())->toContain('format')
            ->and($validator->isValid($base + [
                'relatedIdentifiers' => [[
                    'relatedIdentifier' => 'example.org/related',
                    'relatedIdentifierType' => 'URL',
                    'relationType' => 'References',
                ]],
            ]))->toBeFalse();
    });

    it('does not apply the URI conditional when relatedIdentifierType is missing', function () {
        $validator = new JsonSchemaValidator;
        $errors = null;

        expect($validator->isValid([
            'creators' => [['name' => 'Test Author']],
            'titles' => [['title' => 'Test Title']],
            'publisher' => 'Test Publisher',
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
            'relatedIdentifiers' => [[
                'relatedIdentifier' => 'not a URI',
                'relationType' => 'References',
            ]],
        ], $errors))->toBeFalse();

        $keywords = collect($errors)->pluck('keyword')->all();

        expect($keywords)->toContain('required')
            ->and($keywords)->not->toContain('format');
    });

    it('validates the canonical DataCite polygon representation', function () {
        $base = [
            'creators' => [['name' => 'Test Author']],
            'titles' => [['title' => 'Test Title']],
            'publisher' => 'Test Publisher',
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
        ];
        $polygon = [
            ['polygonPoint' => ['pointLongitude' => 0.0, 'pointLatitude' => 0.0]],
            ['polygonPoint' => ['pointLongitude' => 1.0, 'pointLatitude' => 0.0]],
            ['polygonPoint' => ['pointLongitude' => 1.0, 'pointLatitude' => 1.0]],
            ['polygonPoint' => ['pointLongitude' => 0.0, 'pointLatitude' => 0.0]],
            ['inPolygonPoint' => ['pointLongitude' => 0.5, 'pointLatitude' => 0.5]],
        ];

        $validator = new JsonSchemaValidator;
        expect($validator->validate($base + [
            'geoLocations' => [['geoLocationPolygon' => $polygon]],
        ]))->toBeTrue();

        array_pop($polygon);
        expect($validator->validate($base + [
            'geoLocations' => [['geoLocationPolygon' => $polygon]],
        ]))->toBeTrue();

        $polygonWithTwoInteriorPoints = [...$polygon,
            ['inPolygonPoint' => ['pointLongitude' => 0.4, 'pointLatitude' => 0.4]],
            ['inPolygonPoint' => ['pointLongitude' => 0.5, 'pointLatitude' => 0.5]],
        ];
        expect($validator->isValid($base + [
            'geoLocations' => [['geoLocationPolygon' => $polygonWithTwoInteriorPoints]],
        ]))->toBeFalse();

        array_splice($polygon, 2, 1);
        expect($validator->isValid($base + [
            'geoLocations' => [['geoLocationPolygon' => $polygon]],
        ]))->toBeFalse();
    });

    it('validates related item number fields as siblings', function () {
        $data = [
            'creators' => [['name' => 'Test Author']],
            'titles' => [['title' => 'Test Title']],
            'publisher' => 'Test Publisher',
            'publicationYear' => '2026',
            'types' => ['resourceTypeGeneral' => 'Dataset'],
            'relatedItems' => [[
                'relatedItemType' => 'JournalArticle',
                'relationType' => 'IsPublishedIn',
                'titles' => [['title' => 'Journal']],
                'number' => 'e12345',
                'numberType' => 'Article',
            ]],
        ];

        $validator = new JsonSchemaValidator;
        expect($validator->validate($data))->toBeTrue();

        unset($data['relatedItems'][0]['number']);
        expect($validator->isValid($data))->toBeFalse();
    });
});

describe('JsonValidationException', function () {
    it('stores errors array', function () {
        $errors = [
            ['path' => '/creators', 'message' => 'Required', 'keyword' => 'required', 'context' => []],
        ];

        $exception = new JsonValidationException('Validation failed', $errors);

        expect($exception->getErrors())->toBe($errors);
    });

    it('stores schema version', function () {
        $exception = new JsonValidationException('Validation failed', [], '4.7');

        expect($exception->getSchemaVersion())->toBe('4.7');
    });

    it('returns error messages', function () {
        $errors = [
            ['path' => '/creators', 'message' => 'Field required', 'keyword' => 'required', 'context' => []],
            ['path' => '/titles', 'message' => 'Field required', 'keyword' => 'required', 'context' => []],
        ];

        $exception = new JsonValidationException('Validation failed', $errors);

        expect($exception->getErrorMessages())->toBe(['Field required', 'Field required']);
    });

    it('converts to array', function () {
        $errors = [
            ['path' => '/creators', 'message' => 'Required', 'keyword' => 'required', 'context' => []],
        ];

        $exception = new JsonValidationException('Validation failed', $errors, '4.7');

        $array = $exception->toArray();

        expect($array)->toHaveKey('message');
        expect($array)->toHaveKey('schema_version');
        expect($array)->toHaveKey('errors');
        expect($array['message'])->toBe('Validation failed');
        expect($array['schema_version'])->toBe('4.7');
        expect($array['errors'])->toBe($errors);
    });
});
