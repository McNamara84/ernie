<?php

declare(strict_types=1);

use App\Console\Commands\GetGemetThesaurus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetGemetThesaurus::class);

function fakeGemetApiResponses(): void
{
    $superGroups = [
        [
            'uri' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
            'preferredLabel' => ['string' => 'THE ENVIRONMENT, MAN AND NATURE', 'language' => 'en'],
            'definition' => ['string' => 'Super group definition', 'language' => 'en'],
        ],
    ];

    $groups = [
        [
            'uri' => 'http://www.eionet.europa.eu/gemet/group/5678',
            'preferredLabel' => ['string' => 'ATMOSPHERE', 'language' => 'en'],
            'definition' => ['string' => 'Group definition', 'language' => 'en'],
        ],
    ];

    $broaderConcept = [
            'uri' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
            'preferredLabel' => ['string' => 'THE ENVIRONMENT, MAN AND NATURE', 'language' => 'en'],
        ];

    $groupMembers = [
        [
            'uri' => 'http://www.eionet.europa.eu/gemet/concept/100',
            'preferredLabel' => ['string' => 'air pollution', 'language' => 'en'],
            'definition' => ['string' => 'Concept definition', 'language' => 'en'],
        ],
        [
            'uri' => 'http://www.eionet.europa.eu/gemet/concept/101',
            'preferredLabel' => ['string' => 'climate change', 'language' => 'en'],
            'definition' => ['string' => 'Another concept', 'language' => 'en'],
        ],
    ];

    Http::fake(function (Request $request) use ($superGroups, $groups, $broaderConcept, $groupMembers) {
        $url = $request->url();
        $thesaurusUri = $request->data()['thesaurus_uri'] ?? '';
        $relationUri = $request->data()['relation_uri'] ?? '';

        if (str_contains($url, 'getTopmostConcepts') && $thesaurusUri === 'http://www.eionet.europa.eu/gemet/supergroup/') {
            return Http::response($superGroups, 200);
        }

        if (str_contains($url, 'getTopmostConcepts') && $thesaurusUri === 'http://www.eionet.europa.eu/gemet/group/') {
            return Http::response($groups, 200);
        }

        if (str_contains($url, 'getRelatedConcepts') && $relationUri === 'http://www.w3.org/2004/02/skos/core#broader') {
            return Http::response($broaderConcept, 200);
        }

        if (str_contains($url, 'getRelatedConcepts') && $relationUri === 'http://www.eionet.europa.eu/gemet/2004/06/gemet-schema.rdf#groupMember') {
            return Http::response($groupMembers, 200);
        }

        return Http::response([], 404);
    });
}

it('successfully fetches and saves GEMET thesaurus', function (): void {
    Storage::fake('local');
    fakeGemetApiResponses();

    Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($output)
        ->toContain('Fetching GEMET Thesaurus from EEA API')
        ->toContain('Fetched 1 super groups')
        ->toContain('Fetched 1 groups')
        ->toContain('Mapped 1 groups to super groups')
        ->toContain('gemet-thesaurus.json');
});

it('builds correct hierarchy with concepts nested under groups and supergroups', function (): void {
    Storage::fake('local');
    fakeGemetApiResponses();

    Artisan::call('get-gemet-thesaurus');

    $json = json_decode(\Illuminate\Support\Facades\Storage::get('gemet-thesaurus.json'), true);

    // Hierarchy: SuperGroup → Group → Concepts (not just 4 flat root nodes)
    expect($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['text'])->toBe('THE ENVIRONMENT, MAN AND NATURE')
        ->and($json['data'][0]['children'])->toHaveCount(1)
        ->and($json['data'][0]['children'][0]['text'])->toBe('ATMOSPHERE')
        ->and($json['data'][0]['children'][0]['children'])->toHaveCount(2)
        ->and($json['data'][0]['children'][0]['children'][0]['text'])->toBe('air pollution')
        ->and($json['data'][0]['children'][0]['children'][1]['text'])->toBe('climate change');
});

it('fails when SuperGroups API request fails', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'getTopmostConcepts') && ($request->data()['thesaurus_uri'] ?? '') === 'http://www.eionet.europa.eu/gemet/supergroup/') {
            return Http::response([], 500);
        }

        return Http::response([], 404);
    });

    $exitCode = Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Error');
});

it('fails when Groups API request fails', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'getTopmostConcepts') && ($request->data()['thesaurus_uri'] ?? '') === 'http://www.eionet.europa.eu/gemet/supergroup/') {
            return Http::response([
                ['uri' => 'http://test', 'preferredLabel' => ['string' => 'Test', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
            ], 200);
        }

        if (str_contains($request->url(), 'getTopmostConcepts') && ($request->data()['thesaurus_uri'] ?? '') === 'http://www.eionet.europa.eu/gemet/group/') {
            return Http::response([], 500);
        }

        return Http::response([], 404);
    });

    $exitCode = Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Error');
});

it('handles empty API responses gracefully', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'getTopmostConcepts')) {
            return Http::response([], 200);
        }

        return Http::response([], 404);
    });

    Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($output)
        ->toContain('Fetched 0 super groups')
        ->toContain('Fetched 0 groups');
});
