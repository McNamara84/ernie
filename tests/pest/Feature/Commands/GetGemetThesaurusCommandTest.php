<?php

declare(strict_types=1);

use App\Console\Commands\GetGemetThesaurus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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

    $broaderConcepts = [
        [
            'uri' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
            'preferredLabel' => ['string' => 'THE ENVIRONMENT, MAN AND NATURE', 'language' => 'en'],
        ],
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

    Http::fake([
        '*/getSuperGroupsForScheme*' => Http::response($superGroups, 200),
        '*/getGroupsForScheme*' => Http::response($groups, 200),
        '*/getBroaderConcepts*' => Http::response($broaderConcepts, 200),
        '*/getGroupMembers*' => Http::response($groupMembers, 200),
    ]);
}

it('successfully fetches and saves GEMET thesaurus', function (): void {
    fakeGemetApiResponses();

    Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($output)
        ->toContain('Fetching GEMET Thesaurus from EEA API')
        ->toContain('Fetched 1 super groups')
        ->toContain('Fetched 1 groups')
        ->toContain('Mapped 1 groups to super groups')
        ->toContain('ATMOSPHERE')
        ->toContain('2 concepts')
        ->toContain('gemet-thesaurus.json');
});

it('fails when SuperGroups API request fails', function (): void {
    Http::fake([
        '*/getSuperGroupsForScheme*' => Http::response([], 500),
    ]);

    $exitCode = Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Error');
});

it('fails when Groups API request fails', function (): void {
    Http::fake([
        '*/getSuperGroupsForScheme*' => Http::response([
            ['uri' => 'http://test', 'preferredLabel' => ['string' => 'Test', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
        ], 200),
        '*/getGroupsForScheme*' => Http::response([], 500),
    ]);

    $exitCode = Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Error');
});

it('handles empty API responses gracefully', function (): void {
    Http::fake([
        '*/getSuperGroupsForScheme*' => Http::response([], 200),
        '*/getGroupsForScheme*' => Http::response([], 200),
    ]);

    Artisan::call('get-gemet-thesaurus');
    $output = Artisan::output();

    expect($output)
        ->toContain('Fetched 0 super groups')
        ->toContain('Fetched 0 groups');
});
