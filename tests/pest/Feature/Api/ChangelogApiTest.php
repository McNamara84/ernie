<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\getJson;

it('returns changelog data grouped by release', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'version' => '1.0.9',
            'date' => '2026-09-16',
        ])
        ->assertJsonFragment([
            'title' => 'Traceable Changelog Entries',
        ])
        ->assertJsonFragment([
            'version' => '1.0.8',
            'date' => '2026-09-09',
        ])
        ->assertJsonFragment([
            'title' => 'Single Funding Reference Add Action',
        ])
        ->assertJsonFragment([
            'title' => 'Clear Related Work Empty State',
        ])
        ->assertJsonFragment([
            'version' => '1.0.7',
            'date' => '2026-09-04',
        ])
        ->assertJsonFragment([
            'title' => 'SPDX License Gap Filter for Resources',
        ])
        ->assertJsonFragment([
            'title' => 'Frequently Used Licenses First',
        ])
        ->assertJsonFragment([
            'version' => '1.0.1',
            'date' => '2026-08-25',
        ])
        ->assertJsonFragment([
            'title' => 'Preserved License Drafts in the Data Editor',
        ])
        ->assertJsonFragment([
            'title' => 'Short Abstracts Accepted in the Data Editor',
        ])
        ->assertJsonFragment([
            'title' => 'Corrected Review-Link Migration Emails',
        ])
        ->assertJsonFragment([
            'version' => '0.1.0',
        ])
        ->assertJsonFragment([
            'title' => 'Resources workspace',
        ])
        ->assertJsonFragment([
            'title' => 'Dashboard overview',
        ])
        ->assertJsonFragment([
            'title' => 'Assistance: Description Segmentation Suggestions',
        ])
        ->assertJsonFragment([
            'title' => 'Clear Creative Commons License Labels on Landing Pages',
        ])
        ->assertJsonFragment([
            'title' => 'Expanded Repeatable Metadata Editing',
        ]);
});

it('returns the related GitHub references for every version 1.0.9 changelog entry', function () {
    $payload = getJson('/api/changelog')
        ->assertOk()
        ->json();

    expect($payload)->toBeArray();
    assert(is_array($payload));

    $release = collect($payload)->firstWhere('version', '1.0.9');

    expect($release)->toBeArray();
    assert(is_array($release));

    $changes = collect(['features', 'improvements', 'fixes'])
        ->flatMap(static fn (string $category): array => $release[$category] ?? [])
        ->keyBy('title');

    $expectedReferences = [
        'Traceable Changelog Entries' => [
            [
                'type' => 'issue',
                'number' => 1285,
                'url' => 'https://github.com/McNamara84/ernie/issues/1285',
            ],
            [
                'type' => 'pull_request',
                'number' => 1305,
                'url' => 'https://github.com/McNamara84/ernie/pull/1305',
            ],
        ],
        'Direct Related Work Editing' => [
            [
                'type' => 'issue',
                'number' => 1293,
                'url' => 'https://github.com/McNamara84/ernie/issues/1293',
            ],
            [
                'type' => 'pull_request',
                'number' => 1297,
                'url' => 'https://github.com/McNamara84/ernie/pull/1297',
            ],
        ],
        'Visible Version Guidance and Forthcoming Publications' => [
            [
                'type' => 'pull_request',
                'number' => 1290,
                'url' => 'https://github.com/McNamara84/ernie/pull/1290',
            ],
        ],
        'Streamlined Main Navigation' => [
            [
                'type' => 'pull_request',
                'number' => 1298,
                'url' => 'https://github.com/McNamara84/ernie/pull/1298',
            ],
        ],
        'Readable Controlled Vocabulary Tabs' => [
            [
                'type' => 'issue',
                'number' => 1291,
                'url' => 'https://github.com/McNamara84/ernie/issues/1291',
            ],
            [
                'type' => 'pull_request',
                'number' => 1301,
                'url' => 'https://github.com/McNamara84/ernie/pull/1301',
            ],
        ],
        'Validation-Free Related Work CSV Downloads' => [
            [
                'type' => 'issue',
                'number' => 1282,
                'url' => 'https://github.com/McNamara84/ernie/issues/1282',
            ],
            [
                'type' => 'pull_request',
                'number' => 1294,
                'url' => 'https://github.com/McNamara84/ernie/pull/1294',
            ],
        ],
    ];

    foreach ($expectedReferences as $title => $references) {
        $change = $changes->get($title);

        expect($change)->toBeArray();
        assert(is_array($change));
        expect($change['references'] ?? null)->toBe($references);
    }

    $olderRelease = collect($payload)->firstWhere('version', '1.0.8');
    expect($olderRelease)->toBeArray();
    assert(is_array($olderRelease));

    $olderEntry = collect($olderRelease['improvements'] ?? [])->firstWhere('title', 'One-Click DOI Copying in Resources');
    expect($olderEntry)->toBeArray();
    assert(is_array($olderEntry));
    expect($olderEntry)->not->toHaveKey('references');
});

it('uses safe and internally consistent GitHub reference metadata', function () {
    $payload = getJson('/api/changelog')
        ->assertOk()
        ->json();

    expect($payload)->toBeArray();
    assert(is_array($payload));

    collect($payload)
        ->flatMap(static function (array $release): array {
            return array_merge(
                $release['features'] ?? [],
                $release['improvements'] ?? [],
                $release['fixes'] ?? [],
            );
        })
        ->flatMap(static fn (array $change): array => $change['references'] ?? [])
        ->each(static function (array $reference): void {
            expect($reference)
                ->toHaveKeys(['type', 'number', 'url'])
                ->and($reference['type'])->toBeIn(['issue', 'pull_request'])
                ->and($reference['number'])->toBeInt()->toBeGreaterThan(0)
                ->and($reference['url'])->toBeString();

            assert(is_string($reference['url']));
            $matches = [];
            $isGitHubReference = preg_match(
                '#^https://github\.com/[^/]+/[^/]+/(issues|pull)/([1-9][0-9]*)$#',
                $reference['url'],
                $matches,
            );

            expect($isGitHubReference)->toBe(1);
            assert($isGitHubReference === 1);

            $expectedPath = $reference['type'] === 'pull_request' ? 'pull' : 'issues';

            expect($matches[1])->toBe($expectedPath)
                ->and((int) $matches[2])->toBe($reference['number']);
        });
});

it('returns an error when changelog JSON is invalid', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn('{invalid');

    getJson('/api/changelog')
        ->assertStatus(500)
        ->assertJson([
            'error' => 'Invalid changelog data',
        ]);
});
