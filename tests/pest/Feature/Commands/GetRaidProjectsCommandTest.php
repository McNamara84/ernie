<?php

declare(strict_types=1);

use App\Console\Commands\GetRaidProjects;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

covers(GetRaidProjects::class);

beforeEach(function (): void {
    config([
        'raid.datacite_endpoint' => 'https://api.datacite.example.test',
        'raid.search_query' => 'identifiers.identifier:*raid.org.au*',
        'raid.page_size' => 1,
    ]);
});

it('fetches and stores normalized RAiD projects from DataCite', function (): void {
    Http::fakeSequence('https://api.datacite.example.test/dois*')
        ->push([
            'meta' => [
                'total' => 2,
                'totalPages' => 2,
                'page' => 1,
            ],
            'data' => [
                [
                    'id' => '10.71613/alpha',
                    'attributes' => [
                        'doi' => '10.71613/alpha',
                        'titles' => [
                            ['title' => 'Alpha RAiD Project', 'lang' => 'eng'],
                        ],
                        'descriptions' => [
                            ['description' => 'A public research activity', 'descriptionType' => 'Abstract', 'lang' => 'eng'],
                        ],
                        'url' => 'https://static.prod.raid.org.au/raids/10.71613/alpha',
                        'publicationYear' => 2026,
                        'publisher' => 'Australian Research Data Commons',
                        'dates' => [
                            ['date' => '2026-01-01/2026-12-31', 'dateType' => 'Other'],
                        ],
                        'creators' => [
                            [
                                'name' => 'Jane Example',
                                'nameType' => 'Personal',
                                'nameIdentifiers' => [
                                    ['nameIdentifier' => 'https://orcid.org/0000-0002-1825-0097', 'nameIdentifierScheme' => 'ORCID', 'schemeUri' => 'https://orcid.org/'],
                                ],
                            ],
                        ],
                        'contributors' => [
                            [
                                'name' => 'Example University',
                                'nameType' => 'Organizational',
                                'contributorType' => 'HostingInstitution',
                                'nameIdentifiers' => [
                                    ['nameIdentifier' => 'https://ror.org/04z8jg394', 'nameIdentifierScheme' => 'ROR', 'schemeUri' => 'https://ror.org/'],
                                ],
                            ],
                        ],
                        'relatedIdentifiers' => [
                            ['relatedIdentifier' => 'https://doi.org/10.1234/example', 'relatedIdentifierType' => 'DOI', 'relationType' => 'IsReferencedBy', 'resourceTypeGeneral' => 'Dataset'],
                        ],
                        'subjects' => [
                            ['subject' => 'Geoscience', 'subjectScheme' => null, 'schemeUri' => null, 'valueUri' => null],
                        ],
                        'registered' => '2026-06-01T00:00:00Z',
                        'updated' => '2026-06-25T00:00:00Z',
                    ],
                ],
            ],
        ], 200)
        ->push([
            'meta' => [
                'total' => 2,
                'totalPages' => 2,
                'page' => 2,
            ],
            'data' => [
                [
                    'id' => '10.71613/beta',
                    'attributes' => [
                        'doi' => '10.71613/beta',
                        'titles' => [['title' => 'Beta RAiD Project']],
                        'descriptions' => [],
                        'creators' => [],
                        'contributors' => [],
                    ],
                ],
            ],
        ], 200);

    $outputPath = storage_path('app/testing/'.Str::random(8).'-raid-projects.json');
    File::ensureDirectoryExists(dirname($outputPath));

    $this->artisan('get-raid-projects', ['--output' => $outputPath])
        ->assertExitCode(0);

    $decoded = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['lastUpdated', 'total', 'data'])
        ->and($decoded['total'])->toBe(2)
        ->and($decoded['data'])->toHaveCount(2)
        ->and($decoded['data'][0])->toMatchArray([
            'id' => '10.71613/alpha',
            'doi' => '10.71613/alpha',
            'raidId' => 'https://raid.org/10.71613/alpha',
            'title' => 'Alpha RAiD Project',
            'description' => 'A public research activity',
            'url' => 'https://static.prod.raid.org.au/raids/10.71613/alpha',
            'downloadUrl' => 'https://static.prod.raid.org.au/raids/10.71613/alpha.download/',
            'publicationYear' => 2026,
            'publisher' => 'Australian Research Data Commons',
        ])
        ->and($decoded['data'][0]['contributors'][0]['name'])->toBe('Jane Example')
        ->and($decoded['data'][0]['organisations'][0]['name'])->toBe('Example University')
        ->and($decoded['data'][0]['relatedIdentifiers'][0]['relatedIdentifier'])->toBe('https://doi.org/10.1234/example')
        ->and($decoded['data'][0]['searchTerms'])->toContain('Alpha RAiD Project', 'A public research activity', 'Jane Example', 'Example University');

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['query'] ?? null) === 'identifiers.identifier:*raid.org.au*'
            && (string) ($query['page']['size'] ?? '') === '1'
            && isset($query['page']['number']);
    });

    File::delete($outputPath);
});

it('fails when the DataCite request is unsuccessful', function (): void {
    Http::fake([
        'https://api.datacite.example.test/dois*' => Http::response([], 503),
    ]);

    $this->artisan('get-raid-projects')
        ->expectsOutputToContain('Failed to fetch RAiD projects page 1: HTTP 503')
        ->assertExitCode(1);
});
