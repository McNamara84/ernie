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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
            ];

            expect($validator->validate($validData))->toBeTrue();
        });

        it('throws JsonValidationException for missing required fields', function () {
            $validator = new JsonSchemaValidator;

            $invalidData = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                // Missing: creators, titles, publisher, publicationYear, types, schemaVersion
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
                expect($e->getSchemaVersion())->toBe('4.6');
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

        it('validates all new DataCite 4.6 resourceTypeGeneral values', function () {
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
                    'schemaVersion' => 'http://datacite.org/schema/kernel-4',
                ];

                expect($validator->validate($data))->toBeTrue("Expected {$resourceType} to be valid");
            }
        });

        it('validates new 4.6 contributorType Translator', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
                'contributors' => [
                    ['name' => 'Translator Person', 'contributorType' => 'Translator'],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.6 relationType values', function () {
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
                    'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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

        it('validates new 4.6 relatedIdentifierType CSTR', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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

        it('validates new 4.6 relatedIdentifierType RRID', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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

        it('validates new 4.6 dateType Coverage', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
                'dates' => [
                    ['date' => '2020-01-01/2025-12-31', 'dateType' => 'Coverage'],
                ],
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.6 publisher with object structure', function () {
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
            ];

            expect($validator->validate($data))->toBeTrue();
        });

        it('validates new 4.6 subjects with classificationCode', function () {
            $validator = new JsonSchemaValidator;

            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                        'affiliations' => [
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
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
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
            ];

            // Strict mode - should fail because identifiers are required
            expect(fn () => $validator->validate($data, strictMode: true))
                ->toThrow(JsonValidationException::class);
        });

        it('passes with identifiers in strict mode', function () {
            $validator = new JsonSchemaValidator;

            // Data with identifiers - valid for registration
            $data = [
                'identifiers' => [['identifier' => '10.5880/test', 'identifierType' => 'DOI']],
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
            ];

            // Strict mode with identifiers - should pass
            expect($validator->validate($data, strictMode: true))->toBeTrue();
        });

        it('isValid respects strictMode parameter', function () {
            $validator = new JsonSchemaValidator;

            $dataWithoutIdentifiers = [
                'creators' => [['name' => 'Test Author']],
                'titles' => [['title' => 'Test Title']],
                'publisher' => 'Test Publisher',
                'publicationYear' => '2026',
                'types' => ['resourceType' => 'Dataset', 'resourceTypeGeneral' => 'Dataset'],
                'schemaVersion' => 'http://datacite.org/schema/kernel-4',
            ];

            $errors = null;

            // Non-strict mode - should be valid
            expect($validator->isValid($dataWithoutIdentifiers, $errors, strictMode: false))->toBeTrue();

            // Strict mode - should be invalid
            expect($validator->isValid($dataWithoutIdentifiers, $errors, strictMode: true))->toBeFalse();
            expect($errors)->not->toBeEmpty();
            expect(collect($errors)->pluck('path')->toArray())->toContain('/identifiers');
        });
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
        $exception = new JsonValidationException('Validation failed', [], '4.6');

        expect($exception->getSchemaVersion())->toBe('4.6');
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

        $exception = new JsonValidationException('Validation failed', $errors, '4.6');

        $array = $exception->toArray();

        expect($array)->toHaveKey('message');
        expect($array)->toHaveKey('schema_version');
        expect($array)->toHaveKey('errors');
        expect($array['message'])->toBe('Validation failed');
        expect($array['schema_version'])->toBe('4.6');
        expect($array['errors'])->toBe($errors);
    });
});
