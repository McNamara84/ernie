<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('refreshes cached system packages for every container security scan attempt', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/security.yml'));

    expect($workflow)->toBeArray();

    $containerScan = $workflow['jobs']['container-scan'] ?? null;
    expect($containerScan)->toBeArray();

    $steps = collect($containerScan['steps'] ?? [])->keyBy('name');
    $refreshStep = $steps->get('Resolve system package refresh date');
    $buildStep = $steps->get('Build application image');
    $trivyCacheStep = $steps->get('Cache Trivy databases');
    $sarifUploadStep = $steps->get('Upload Trivy scan results');
    $vulnerabilityGateStep = $steps->get('Fail on high or critical image vulnerabilities');

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
        ->toContain('SYSTEM_PACKAGES_REFRESH=${{ steps.system-packages-refresh.outputs.date }}-${{ github.run_id }}-${{ github.run_attempt }}')
        ->and($trivyCacheStep)
        ->toBeArray()
        ->and($trivyCacheStep['with']['key'] ?? null)
        ->toBe('${{ runner.os }}-trivy-${{ steps.system-packages-refresh.outputs.date }}')
        ->and($sarifUploadStep)
        ->toBeArray()
        ->and($sarifUploadStep['if'] ?? null)
        ->toContain("hashFiles('trivy-results.sarif') != ''")
        ->and($sarifUploadStep)->not->toHaveKey('continue-on-error')
        ->and($vulnerabilityGateStep)
        ->toBeArray()
        ->and($vulnerabilityGateStep['if'] ?? null)
        ->toContain('always()')
        ->toContain("hashFiles('ernie-security-scan.tar') != ''")
        ->and($vulnerabilityGateStep['run'] ?? null)
        ->toBeString()
        ->toContain('--exit-code 1');

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

it('retries the verified Node archive download in the production image', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)
        ->toBeString()
        ->toContain('--retry 5')
        ->toContain('--retry-all-errors')
        ->toContain('--retry-max-time 120')
        ->toContain('--connect-timeout 20')
        ->toContain('echo "${NODE_CHECKSUM}  /tmp/${NODE_ARCHIVE}" | sha256sum -c -');
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
