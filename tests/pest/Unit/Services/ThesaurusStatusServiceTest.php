<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Services\ThesaurusStatusService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(ThesaurusStatusService::class);

describe('ThesaurusStatusService', function () {
    beforeEach(function () {
        $this->service = new ThesaurusStatusService;
    });

    describe('getLocalStatus', function () {
        it('returns not-exists status when file does not exist', function () {
            Storage::fake('local');

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getLocalStatus($thesaurus);

            expect($result['exists'])->toBeFalse();
            expect($result['conceptCount'])->toBe(0);
            expect($result['lastUpdated'])->toBeNull();
        });

        it('returns not-exists status for empty file', function () {
            Storage::fake('local');
            Storage::put('gcmd-science-keywords.json', '');

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getLocalStatus($thesaurus);

            expect($result['exists'])->toBeFalse();
        });

        it('returns not-exists status for invalid JSON', function () {
            Storage::fake('local');
            Storage::put('gcmd-science-keywords.json', 'not-valid-json');

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getLocalStatus($thesaurus);

            expect($result['exists'])->toBeFalse();
        });

        it('counts flat data array', function () {
            Storage::fake('local');
            Storage::put('gcmd-science-keywords.json', json_encode([
                'lastUpdated' => '2025-03-01T12:00:00Z',
                'data' => [
                    ['label' => 'Earth Science'],
                    ['label' => 'Atmosphere'],
                    ['label' => 'Ocean'],
                ],
            ]));

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getLocalStatus($thesaurus);

            expect($result['exists'])->toBeTrue();
            expect($result['conceptCount'])->toBe(3);
            expect($result['lastUpdated'])->toBe('2025-03-01T12:00:00Z');
        });

        it('counts hierarchical data recursively', function () {
            Storage::fake('local');
            Storage::put('gcmd-science-keywords.json', json_encode([
                'lastUpdated' => '2025-03-01T12:00:00Z',
                'data' => [
                    [
                        'label' => 'Earth Science',
                        'children' => [
                            ['label' => 'Atmosphere'],
                            [
                                'label' => 'Hydrosphere',
                                'children' => [
                                    ['label' => 'Surface Water'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]));

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getLocalStatus($thesaurus);

            expect($result['exists'])->toBeTrue();
            // 1 (Earth Science) + 2 (Atmosphere, Hydrosphere) + 1 (Surface Water) = 4
            expect($result['conceptCount'])->toBe(4);
        });
    });

    describe('getRemoteConceptCount', function () {
        it('queries NASA KMS API for GCMD thesauri', function () {
            Http::fake([
                'cmr.earthdata.nasa.gov/*' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
                    .' xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">'
                    .'<gcmd:gcmd><gcmd:hits>4200</gcmd:hits></gcmd:gcmd>'
                    .'</rdf:RDF>',
                    200,
                    ['Content-Type' => 'application/rdf+xml']
                ),
            ]);

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->getRemoteConceptCount($thesaurus);

            expect($result)->toBe(4200);
        });

        it('throws RuntimeException for unsupported thesaurus type', function () {
            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = 'unknown_type';

            $this->service->getRemoteConceptCount($thesaurus);
        })->throws(\RuntimeException::class, 'Unsupported thesaurus type');

        it('throws RuntimeException on API failure', function () {
            Http::fake([
                'cmr.earthdata.nasa.gov/*' => Http::response('Error', 500),
            ]);

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $this->service->getRemoteConceptCount($thesaurus);
        })->throws(\RuntimeException::class, 'Failed to fetch from NASA KMS API');
    });

    describe('compareWithRemote', function () {
        it('detects update available when remote has more concepts', function () {
            Storage::fake('local');
            Storage::put('gcmd-science-keywords.json', json_encode([
                'lastUpdated' => '2025-01-01T00:00:00Z',
                'data' => [
                    ['label' => 'Earth Science'],
                    ['label' => 'Atmosphere'],
                ],
            ]));

            Http::fake([
                'cmr.earthdata.nasa.gov/*' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
                    .' xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">'
                    .'<gcmd:gcmd><gcmd:hits>100</gcmd:hits></gcmd:gcmd>'
                    .'</rdf:RDF>',
                    200,
                    ['Content-Type' => 'application/rdf+xml']
                ),
            ]);

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_SCIENCE_KEYWORDS;

            $result = $this->service->compareWithRemote($thesaurus);

            expect($result['localCount'])->toBe(2);
            expect($result['remoteCount'])->toBe(100);
            expect($result['updateAvailable'])->toBeTrue();
            expect($result['lastUpdated'])->toBe('2025-01-01T00:00:00Z');
        });

        it('detects no update when counts are equal', function () {
            Storage::fake('local');
            Storage::put('gcmd-platforms.json', json_encode([
                'lastUpdated' => '2025-03-01T00:00:00Z',
                'data' => [
                    ['label' => 'Platform 1'],
                    ['label' => 'Platform 2'],
                ],
            ]));

            Http::fake([
                'cmr.earthdata.nasa.gov/*' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
                    .' xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">'
                    .'<gcmd:gcmd><gcmd:hits>2</gcmd:hits></gcmd:gcmd>'
                    .'</rdf:RDF>',
                    200,
                    ['Content-Type' => 'application/rdf+xml']
                ),
            ]);

            $thesaurus = new ThesaurusSetting;
            $thesaurus->type = ThesaurusSetting::TYPE_PLATFORMS;

            $result = $this->service->compareWithRemote($thesaurus);

            expect($result['updateAvailable'])->toBeFalse();
        });
    });
});
