<?php

declare(strict_types=1);

use App\Console\Commands\GetEuroSciVoc;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetEuroSciVoc::class);

/**
 * Build a minimal valid EuroSciVoc RDF/XML string for testing.
 * Contains 2 concepts: one top-level, one child.
 */
function buildMinimalEuroSciVocRdf(): string
{
    $schemeUri = config('euroscivoc.concept_scheme_uri');

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label rdf:about="http://example.org/label/1">
        <skosxl:literalForm xml:lang="en">natural sciences</skosxl:literalForm>
    </skosxl:Label>
    <skosxl:Label rdf:about="http://example.org/label/2">
        <skosxl:literalForm xml:lang="en">physics</skosxl:literalForm>
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:topConceptOf rdf:resource="{$schemeUri}"/>
        <skos:inScheme rdf:resource="{$schemeUri}"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/1"/>
    </skos:Concept>
    <skos:Concept rdf:about="http://example.org/concept/2">
        <skos:inScheme rdf:resource="{$schemeUri}"/>
        <skos:broader rdf:resource="http://example.org/concept/1"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/2"/>
    </skos:Concept>
</rdf:RDF>
XML;
}

it('successfully fetches and saves EuroSciVoc vocabulary', function (): void {
    Storage::fake('local');

    Http::fake([
        config('euroscivoc.download_url') => Http::response(buildMinimalEuroSciVocRdf(), 200),
    ]);

    Artisan::call('get-euroscivoc');
    $output = Artisan::output();

    expect($output)
        ->toContain('Fetching European Science Vocabulary')
        ->toContain('Downloading RDF')
        ->toContain('Extracted 2 concepts')
        ->toContain('Built hierarchy with 2 concepts')
        ->toContain('Successfully saved');

    Storage::assertExists('euroscivoc.json');
});

it('builds correct hierarchical structure', function (): void {
    Storage::fake('local');

    Http::fake([
        config('euroscivoc.download_url') => Http::response(buildMinimalEuroSciVocRdf(), 200),
    ]);

    Artisan::call('get-euroscivoc');

    $json = json_decode(Storage::get('euroscivoc.json'), true);

    expect($json)->toHaveKeys(['lastUpdated', 'data'])
        ->and($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['text'])->toBe('natural sciences')
        ->and($json['data'][0]['scheme'])->toBe(config('euroscivoc.scheme_name'))
        ->and($json['data'][0]['children'])->toHaveCount(1)
        ->and($json['data'][0]['children'][0]['text'])->toBe('physics');
});

it('fails gracefully on HTTP error', function (): void {
    Storage::fake('local');

    Http::fake([
        config('euroscivoc.download_url') => Http::response('Server Error', 500),
    ]);

    $exitCode = Artisan::call('get-euroscivoc');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Failed to download EuroSciVoc RDF: HTTP 500');

    Storage::assertMissing('euroscivoc.json');
});

it('fails when no concepts are found', function (): void {
    Storage::fake('local');

    $emptyRdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
</rdf:RDF>
XML;

    Http::fake([
        config('euroscivoc.download_url') => Http::response($emptyRdf, 200),
    ]);

    $exitCode = Artisan::call('get-euroscivoc');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('No concepts found');
});

it('fails when RDF content is invalid XML', function (): void {
    Storage::fake('local');

    Http::fake([
        config('euroscivoc.download_url') => Http::response('not valid xml at all', 200),
    ]);

    $exitCode = Artisan::call('get-euroscivoc');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Error:');
});

it('saves valid JSON with required structure', function (): void {
    Storage::fake('local');

    Http::fake([
        config('euroscivoc.download_url') => Http::response(buildMinimalEuroSciVocRdf(), 200),
    ]);

    Artisan::call('get-euroscivoc');

    $json = json_decode(Storage::get('euroscivoc.json'), true);

    expect($json)->toBeArray()
        ->and($json['lastUpdated'])->toBeString()->not->toBeEmpty()
        ->and($json['data'])->toBeArray()
        ->and($json['data'][0])->toHaveKeys(['id', 'text', 'language', 'scheme', 'schemeURI', 'description', 'children']);
});
