<?php

declare(strict_types=1);

use App\Console\Commands\GetGcmdInstruments;
use App\Console\Commands\GetGcmdPlatforms;
use App\Console\Commands\GetGcmdScienceKeywords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetGcmdScienceKeywords::class, GetGcmdInstruments::class, GetGcmdPlatforms::class);

// =========================================================================
// Concrete command config
// =========================================================================

describe('command configuration', function (): void {
    it('GetGcmdScienceKeywords has correct config', function (): void {
        $command = new GetGcmdScienceKeywords;

        expect((new ReflectionMethod($command, 'getVocabularyType'))->invoke($command))->toBe('sciencekeywords')
            ->and((new ReflectionMethod($command, 'getOutputFile'))->invoke($command))->toBe('gcmd-science-keywords.json')
            ->and((new ReflectionMethod($command, 'getDisplayName'))->invoke($command))->toBe('GCMD Science Keywords')
            ->and((new ReflectionMethod($command, 'getSchemeTitle'))->invoke($command))->toBe('NASA/GCMD Earth Science Keywords')
            ->and((new ReflectionMethod($command, 'getSchemeURI'))->invoke($command))->toContain('sciencekeywords');
    });

    it('GetGcmdInstruments has correct config', function (): void {
        $command = new GetGcmdInstruments;

        expect((new ReflectionMethod($command, 'getVocabularyType'))->invoke($command))->toBe('instruments')
            ->and((new ReflectionMethod($command, 'getOutputFile'))->invoke($command))->toBe('gcmd-instruments.json')
            ->and((new ReflectionMethod($command, 'getDisplayName'))->invoke($command))->toBe('GCMD Instruments');
    });

    it('GetGcmdPlatforms has correct config', function (): void {
        $command = new GetGcmdPlatforms;

        expect((new ReflectionMethod($command, 'getVocabularyType'))->invoke($command))->toBe('platforms')
            ->and((new ReflectionMethod($command, 'getOutputFile'))->invoke($command))->toBe('gcmd-platforms.json')
            ->and((new ReflectionMethod($command, 'getDisplayName'))->invoke($command))->toBe('GCMD Platforms');
    });
});

// =========================================================================
// handle() – success & failure paths
// =========================================================================

describe('handle', function (): void {
    it('fetches, parses, and stores vocabulary data on success', function (): void {
        Storage::fake('local');

        $rdfPage = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
    <gcmd:gcmd><gcmd:hits>1</gcmd:hits></gcmd:gcmd>
    <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/abc-123">
        <skos:prefLabel xml:lang="en">Earth Science</skos:prefLabel>
        <skos:definition>Study of Earth</skos:definition>
    </skos:Concept>
</rdf:RDF>
XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfPage, 200),
        ]);

        $this->artisan('get-gcmd-science-keywords')
            ->assertExitCode(0);

        Storage::assertExists('gcmd-science-keywords.json');

        $json = json_decode(Storage::get('gcmd-science-keywords.json'), true);
        expect($json)->toHaveKeys(['lastUpdated', 'data'])
            ->and($json['data'])->toHaveCount(1)
            ->and($json['data'][0]['text'])->toBe('Earth Science');
    });

    it('returns failure exit code on API error', function (): void {
        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response('Server Error', 500),
        ]);

        $this->artisan('get-gcmd-science-keywords')
            ->assertExitCode(1);
    });

    it('returns failure exit code on exception', function (): void {
        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response('not-valid-xml', 200),
        ]);

        $this->artisan('get-gcmd-science-keywords')
            ->assertExitCode(1);
    });
});

// =========================================================================
// countConcepts
// =========================================================================

describe('countConcepts', function (): void {
    it('counts flat concepts', function (): void {
        $command = new GetGcmdScienceKeywords;
        $method = new ReflectionMethod($command, 'countConcepts');

        $data = [
            ['text' => 'A', 'children' => []],
            ['text' => 'B', 'children' => []],
        ];

        expect($method->invoke($command, $data))->toBe(2);
    });

    it('counts nested concepts recursively', function (): void {
        $command = new GetGcmdScienceKeywords;
        $method = new ReflectionMethod($command, 'countConcepts');

        $data = [
            [
                'text' => 'Root',
                'children' => [
                    [
                        'text' => 'Child',
                        'children' => [
                            ['text' => 'Grandchild', 'children' => []],
                        ],
                    ],
                ],
            ],
        ];

        expect($method->invoke($command, $data))->toBe(3);
    });
});
