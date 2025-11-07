<?php

use App\Services\MslVocabularyService;
use Illuminate\Support\Facades\Http;

it('successfully executes the get-msl-keywords command', function () {
    // Mock the GitHub API response with a simplified MSL vocabulary structure
    $vocabularyData = [
        [
            'text' => 'Material',
            'extra' => [
                'uri' => 'http://w3id.org/msl/voc/materials/material',
                'description' => 'Sample material type',
            ],
            'children' => [
                [
                    'text' => 'Rock',
                    'extra' => [
                        'uri' => 'http://w3id.org/msl/voc/materials/rock',
                        'description' => 'Rock sample',
                    ],
                    'children' => [],
                ],
            ],
        ],
    ];

    Http::fake([
        'https://raw.githubusercontent.com/UtrechtUniversity/msl_vocabularies/main/vocabularies/combined/editor/1.3/editor_1-3.json' => Http::response($vocabularyData, 200),
    ]);

    $this->artisan('get-msl-keywords')
        ->expectsOutputToContain('Fetching MSL keywords from GitHub')
        ->expectsOutputToContain('MSL keywords downloaded and transformed successfully')
        ->expectsOutputToContain('concepts extracted')
        ->expectsOutputToContain('Material')
        ->assertExitCode(0);
});

it('fails when the GitHub request is unsuccessful', function () {
    Http::fake([
        'https://raw.githubusercontent.com/*' => Http::response([], 404),
    ]);

    $this->artisan('get-msl-keywords')
        ->expectsOutputToContain('Failed to download MSL keywords')
        ->assertExitCode(1);
});

it('handles invalid JSON content gracefully', function () {
    Http::fake([
        'https://raw.githubusercontent.com/*' => Http::response('Invalid JSON content', 200),
    ]);

    $this->artisan('get-msl-keywords')
        ->expectsOutputToContain('Failed to download MSL keywords')
        ->assertExitCode(1);
});

