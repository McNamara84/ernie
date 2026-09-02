<?php

declare(strict_types=1);

it('documents the administrator log download workflow', function (): void {
    $documentation = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($documentation)
        ->toBeString()
        ->toContain("id: 'application-logs'")
        ->toContain("minRole: 'admin'")
        ->toContain('Last 24 hours')
        ->toContain('Last 7 days')
        ->toContain('Last 30 days')
        ->toContain("current table's level")
        ->toContain('search filters do not limit the file');
});

it('announces log downloads in the changelog', function (): void {
    $contents = file_get_contents(resource_path('data/changelog.json'));
    expect($contents)->toBeString();
    assert(is_string($contents));

    $releases = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    expect($releases)->toBeArray();
    assert(is_array($releases));

    $entry = collect($releases)
        ->flatMap(static fn (array $release): array => $release['features'] ?? [])
        ->firstWhere('title', 'Downloadable Application Logs');

    expect($entry)
        ->toBeArray()
        ->and($entry['description'] ?? null)
        ->toBeString()
        ->toContain('last 24 hours, 7 days, or 30 days')
        ->toContain('administrator-only access controls');
});
