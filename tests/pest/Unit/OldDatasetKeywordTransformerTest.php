<?php

use App\Services\OldDatasetKeywordTransformer;

describe('OldDatasetKeywordTransformer', function () {
    describe('extractUuidFromOldUri', function () {
        it('extracts UUID from old GCMD science keywords URI', function () {
            $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5';
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri($oldUri);
            
            expect($uuid)->toBe('e9f67a66-e9fc-435c-b720-ae32a2c3d8f5');
        });

        it('extracts UUID from old GCMD platforms URI', function () {
            $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/227d9c3d-f631-402d-84ed-b8c5a562fc27';
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri($oldUri);
            
            expect($uuid)->toBe('227d9c3d-f631-402d-84ed-b8c5a562fc27');
        });

        it('extracts UUID from old GCMD instruments URI', function () {
            $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/6015ef7b-f3bd-49e1-9193-cc23db566b69';
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri($oldUri);
            
            expect($uuid)->toBe('6015ef7b-f3bd-49e1-9193-cc23db566b69');
        });

        it('handles mixed case UUIDs', function () {
            $oldUri = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/E9F67A66-E9FC-435C-B720-AE32A2C3D8F5';
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri($oldUri);
            
            expect($uuid)->toBe('E9F67A66-E9FC-435C-B720-AE32A2C3D8F5');
        });

        it('returns null for URIs without UUID', function () {
            $oldUri = 'http://example.com/no-uuid-here';
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri($oldUri);
            
            expect($uuid)->toBeNull();
        });

        it('returns null for null input', function () {
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri(null);
            
            expect($uuid)->toBeNull();
        });

        it('returns null for empty string', function () {
            $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri('');
            
            expect($uuid)->toBeNull();
        });
    });

    describe('constructNewUri', function () {
        it('constructs correct new URI from UUID', function () {
            $uuid = 'e9f67a66-e9fc-435c-b720-ae32a2c3d8f5';
            $newUri = OldDatasetKeywordTransformer::constructNewUri($uuid);
            
            expect($newUri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5');
        });

        it('handles mixed case UUIDs', function () {
            $uuid = 'E9F67A66-E9FC-435C-B720-AE32A2C3D8F5';
            $newUri = OldDatasetKeywordTransformer::constructNewUri($uuid);
            
            expect($newUri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/E9F67A66-E9FC-435C-B720-AE32A2C3D8F5');
        });
    });

    describe('mapVocabularyType', function () {
        it('maps NASA/GCMD Earth Science Keywords to gcmd-science-keywords', function () {
            $type = OldDatasetKeywordTransformer::mapVocabularyType('NASA/GCMD Earth Science Keywords');
            
            expect($type)->toBe('gcmd-science-keywords');
        });

        it('maps GCMD Platforms to gcmd-platforms', function () {
            $type = OldDatasetKeywordTransformer::mapVocabularyType('GCMD Platforms');
            
            expect($type)->toBe('gcmd-platforms');
        });

        it('maps GCMD Instruments to gcmd-instruments', function () {
            $type = OldDatasetKeywordTransformer::mapVocabularyType('GCMD Instruments');
            
            expect($type)->toBe('gcmd-instruments');
        });

        it('returns null for unsupported thesaurus', function () {
            $type = OldDatasetKeywordTransformer::mapVocabularyType('Unsupported Thesaurus');
            
            expect($type)->toBeNull();
        });

        it('returns null for empty string', function () {
            $type = OldDatasetKeywordTransformer::mapVocabularyType('');
            
            expect($type)->toBeNull();
        });
    });

    describe('transform', function () {
        it('transforms a complete old keyword to new format', function () {
            $oldKeyword = (object) [
                'keyword' => 'EARTH SCIENCE > AGRICULTURE',
                'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a956d045-3b12-441c-8a18-fac7d33b2b4e',
                'description' => 'Test description',
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBe([
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/a956d045-3b12-441c-8a18-fac7d33b2b4e',
                'text' => 'EARTH SCIENCE > AGRICULTURE',
                'vocabulary' => 'gcmd-science-keywords',
                'path' => 'EARTH SCIENCE > AGRICULTURE',
                'uuid' => 'a956d045-3b12-441c-8a18-fac7d33b2b4e',
                'description' => 'Test description',
            ]);
        });

        it('transforms GCMD Platforms keyword', function () {
            $oldKeyword = (object) [
                'keyword' => 'Aircraft > A340-600',
                'thesaurus' => 'GCMD Platforms',
                'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/bab77f95-aa34-42aa-9a12-922d1c9fae63',
                'description' => null,
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBe([
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/bab77f95-aa34-42aa-9a12-922d1c9fae63',
                'text' => 'Aircraft > A340-600',
                'vocabulary' => 'gcmd-platforms',
                'path' => 'Aircraft > A340-600',
                'uuid' => 'bab77f95-aa34-42aa-9a12-922d1c9fae63',
                'description' => null,
            ]);
        });

        it('transforms GCMD Instruments keyword', function () {
            $oldKeyword = (object) [
                'keyword' => 'Earth Remote Sensing Instruments',
                'thesaurus' => 'GCMD Instruments',
                'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/6015ef7b-f3bd-49e1-9193-cc23db566b69',
                'description' => '',
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBe([
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/6015ef7b-f3bd-49e1-9193-cc23db566b69',
                'text' => 'Earth Remote Sensing Instruments',
                'vocabulary' => 'gcmd-instruments',
                'path' => 'Earth Remote Sensing Instruments',
                'uuid' => '6015ef7b-f3bd-49e1-9193-cc23db566b69',
                'description' => '',
            ]);
        });

        it('returns null for keyword without UUID in URI', function () {
            $oldKeyword = (object) [
                'keyword' => 'Some Keyword',
                'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                'uri' => 'http://example.com/no-uuid',
                'description' => null,
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBeNull();
        });

        it('returns null for keyword with unsupported thesaurus', function () {
            $oldKeyword = (object) [
                'keyword' => 'Some Keyword',
                'thesaurus' => 'Unsupported Thesaurus',
                'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a956d045-3b12-441c-8a18-fac7d33b2b4e',
                'description' => null,
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBeNull();
        });

        it('returns null for keyword without URI', function () {
            $oldKeyword = (object) [
                'keyword' => 'Some Keyword',
                'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                'uri' => null,
                'description' => null,
            ];

            $result = OldDatasetKeywordTransformer::transform($oldKeyword);

            expect($result)->toBeNull();
        });
    });

    describe('transformMany', function () {
        it('transforms multiple keywords', function () {
            $oldKeywords = [
                (object) [
                    'keyword' => 'EARTH SCIENCE',
                    'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5',
                    'description' => null,
                ],
                (object) [
                    'keyword' => 'Aircraft',
                    'thesaurus' => 'GCMD Platforms',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/227d9c3d-f631-402d-84ed-b8c5a562fc27',
                    'description' => null,
                ],
            ];

            $result = OldDatasetKeywordTransformer::transformMany($oldKeywords);

            expect($result)->toHaveCount(2);
            expect($result[0])->toBe([
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5',
                'text' => 'EARTH SCIENCE',
                'vocabulary' => 'gcmd-science-keywords',
                'path' => 'EARTH SCIENCE',
                'uuid' => 'e9f67a66-e9fc-435c-b720-ae32a2c3d8f5',
                'description' => null,
            ]);
            expect($result[1])->toBe([
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/227d9c3d-f631-402d-84ed-b8c5a562fc27',
                'text' => 'Aircraft',
                'vocabulary' => 'gcmd-platforms',
                'path' => 'Aircraft',
                'uuid' => '227d9c3d-f631-402d-84ed-b8c5a562fc27',
                'description' => null,
            ]);
        });

        it('filters out keywords that cannot be transformed', function () {
            $oldKeywords = [
                (object) [
                    'keyword' => 'EARTH SCIENCE',
                    'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5',
                    'description' => null,
                ],
                (object) [
                    'keyword' => 'Invalid',
                    'thesaurus' => 'Unsupported Thesaurus',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a956d045-3b12-441c-8a18-fac7d33b2b4e',
                    'description' => null,
                ],
                (object) [
                    'keyword' => 'Aircraft',
                    'thesaurus' => 'GCMD Platforms',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/227d9c3d-f631-402d-84ed-b8c5a562fc27',
                    'description' => null,
                ],
            ];

            $result = OldDatasetKeywordTransformer::transformMany($oldKeywords);

            expect($result)->toHaveCount(2);
            expect($result[0]['text'])->toBe('EARTH SCIENCE');
            expect($result[1]['text'])->toBe('Aircraft');
        });

        it('returns empty array for empty input', function () {
            $result = OldDatasetKeywordTransformer::transformMany([]);
            
            expect($result)->toBe([]);
        });
    });

    describe('getSupportedThesauri', function () {
        it('returns all supported thesaurus names', function () {
            $supported = OldDatasetKeywordTransformer::getSupportedThesauri();

            expect($supported)->toBe([
                'NASA/GCMD Earth Science Keywords',
                'GCMD Platforms',
                'GCMD Instruments',
            ]);
        });
    });
});
