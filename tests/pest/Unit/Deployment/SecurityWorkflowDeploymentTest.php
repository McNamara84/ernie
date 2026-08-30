<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('refreshes cached system packages daily for the container security scan', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/security.yml'));

    expect($workflow)->toBeArray();

    $containerScan = $workflow['jobs']['container-scan'] ?? null;
    expect($containerScan)->toBeArray();

    $steps = collect($containerScan['steps'] ?? [])->keyBy('name');
    $refreshStep = $steps->get('Resolve system package refresh date');
    $buildStep = $steps->get('Build application image');
    $trivyCacheStep = $steps->get('Cache Trivy databases');

    expect($refreshStep)
        ->toBeArray()
        ->and($refreshStep['id'] ?? null)->toBe('system-packages-refresh')
        ->and($refreshStep['run'] ?? null)
        ->toBeString()
        ->toContain("date +'%Y-%m-%d'")
        ->and($buildStep)
        ->toBeArray()
        ->and($buildStep['with']['build-args'] ?? null)
        ->toBeString()
        ->toContain('SYSTEM_PACKAGES_REFRESH=${{ steps.system-packages-refresh.outputs.date }}')
        ->and($trivyCacheStep)
        ->toBeArray()
        ->and($trivyCacheStep['with']['key'] ?? null)
        ->toBe('${{ runner.os }}-trivy-${{ steps.system-packages-refresh.outputs.date }}');

    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)
        ->toBeString()
        ->toContain('ARG SYSTEM_PACKAGES_REFRESH=manual')
        ->toContain('System package refresh: ${SYSTEM_PACKAGES_REFRESH}');

    assert(is_string($dockerfile));

    $refreshArgumentPosition = strpos($dockerfile, 'ARG SYSTEM_PACKAGES_REFRESH=manual');
    $packageRefreshPosition = strpos($dockerfile, 'RUN echo "System package refresh: ${SYSTEM_PACKAGES_REFRESH}"');

    expect($refreshArgumentPosition)
        ->toBeInt()
        ->and($packageRefreshPosition)->toBeInt();

    assert(is_int($refreshArgumentPosition));
    assert(is_int($packageRefreshPosition));

    expect($refreshArgumentPosition)->toBeLessThan($packageRefreshPosition);
});

it('excludes local temporary tooling from production image contexts', function (): void {
    $dockerIgnore = file_get_contents(base_path('.dockerignore'));

    expect($dockerIgnore)->toBeString();
    assert(is_string($dockerIgnore));

    $ignoredPaths = preg_split('/\R/', $dockerIgnore);
    expect($ignoredPaths)
        ->toBeArray()
        ->toContain('/.tmp');
});
