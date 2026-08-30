<?php

declare(strict_types=1);

it('announces cursor pagination and recoverable asynchronous resource counts', function (): void {
    $contents = file_get_contents(resource_path('data/changelog.json'));
    expect($contents)->toBeString();
    assert(is_string($contents));

    $releases = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    expect($releases)->toBeArray();
    assert(is_array($releases));

    $entry = collect($releases)
        ->flatMap(static fn (array $release): array => $release['improvements'] ?? [])
        ->firstWhere('title', 'Responsive Resource Curation List');

    expect($entry)
        ->toBeArray()
        ->and($entry['description'] ?? null)
        ->toBeString()
        ->toContain('cursor pagination')
        ->toContain('counting is in progress')
        ->toContain('Retry');
});

it('documents pending and failed resource count recovery for every resource-list role', function (): void {
    $documentation = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($documentation)
        ->toBeString()
        ->toContain("id: 'bulk-actions'")
        ->toContain("minRole: 'beginner'")
        ->toContain('Large Lists and Resource Counts')
        ->toContain('Counting resources...')
        ->toContain('Count unavailable')
        ->toContain('<strong>Retry</strong>');
});

it('includes the resource listing projection and its resource relationship in both ER diagrams', function (): void {
    $mermaid = file_get_contents(database_path('er-diagram.md'));
    $plantUml = file_get_contents(database_path('er-diagram-plantuml.md'));

    expect($mermaid)
        ->toBeString()
        ->toContain('resource_listing_projections {')
        ->toContain('bigint resource_id PK,FK')
        ->toContain('resources ||--o| resource_listing_projections')
        ->and($plantUml)
        ->toBeString()
        ->toContain('entity "resource_listing_projections" as resource_listing_projections')
        ->toContain('* **resource_id** : BIGINT <<PK, FK>>')
        ->toContain('resources ||--o| resource_listing_projections');
});
