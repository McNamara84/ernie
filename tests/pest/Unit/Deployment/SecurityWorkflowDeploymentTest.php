<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('scans every deployed runtime image while retaining the dated build caches', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/security.yml'));

    expect($workflow)->toBeArray();

    $containerScan = $workflow['jobs']['container-scan'] ?? null;
    expect($containerScan)->toBeArray();

    $steps = collect($containerScan['steps'] ?? [])->keyBy('name');
    $refreshStep = $steps->get('Resolve system package refresh date');
    $applicationBuildStep = $steps->get('Build application image');
    $nginxBuildStep = $steps->get('Build Nginx image');
    $exportStep = $steps->get('Export runtime images');
    $trivyCacheStep = $steps->get('Cache Trivy databases');
    $trivyScanStep = $steps->get('Run Trivy vulnerability scanner');
    $sarifCheckStep = $steps->get('Check Trivy SARIF output');
    $sarifUploadStep = $steps->get('Upload Trivy scan results');
    $sarifArtifactStep = $steps->get('Upload Trivy SARIF artifact');
    $vulnerabilityGateStep = $steps->get('Fail on high or critical image vulnerabilities');

    expect($refreshStep)
        ->toBeArray()
        ->and($refreshStep['id'] ?? null)->toBe('system-packages-refresh')
        ->and($refreshStep['run'] ?? null)
        ->toBeString()
        ->toContain("date +'%Y-%m-%d'")
        ->and($applicationBuildStep)
        ->toBeArray()
        ->and($applicationBuildStep['with']['target'] ?? null)->toBe('app')
        ->and($applicationBuildStep['with']['tags'] ?? null)
        ->toBe('ernie-app-security-scan:${{ github.sha }}')
        ->and($applicationBuildStep['with']['build-args'] ?? null)
        ->toBeString()
        ->toContain('SYSTEM_PACKAGES_REFRESH=${{ steps.system-packages-refresh.outputs.date }}')
        ->not->toContain('github.run_id')
        ->not->toContain('github.run_attempt')
        ->and($nginxBuildStep)
        ->toBeArray()
        ->and($nginxBuildStep['with']['target'] ?? null)->toBe('nginx')
        ->and($nginxBuildStep['with']['tags'] ?? null)
        ->toBe('ernie-nginx-security-scan:${{ github.sha }}')
        ->and($nginxBuildStep['with']['build-args'] ?? null)
        ->toBeString()
        ->toContain('SYSTEM_PACKAGES_REFRESH=${{ steps.system-packages-refresh.outputs.date }}')
        ->and($nginxBuildStep['with']['cache-from'] ?? null)
        ->toBeString()
        ->toContain('type=gha,scope=security-app')
        ->toContain('type=gha,scope=security-nginx')
        ->and($exportStep)
        ->toBeArray()
        ->and($exportStep['run'] ?? null)
        ->toBeString()
        ->toContain('docker save -o ernie-app-security-scan.tar')
        ->toContain('docker save -o ernie-nginx-security-scan.tar')
        ->and($trivyCacheStep)
        ->toBeArray()
        ->and($trivyCacheStep['with']['key'] ?? null)
        ->toBe('${{ runner.os }}-trivy-${{ steps.system-packages-refresh.outputs.date }}')
        ->and($trivyScanStep)
        ->toBeArray()
        ->and($trivyScanStep['run'] ?? null)
        ->toBeString()
        ->toContain('for target in app nginx')
        ->toContain('--input "/work/ernie-${target}-security-scan.tar"')
        ->toContain('--output "/work/trivy-results/${target}.sarif"')
        ->and($sarifCheckStep)
        ->toBeArray()
        ->and($sarifCheckStep['id'] ?? null)->toBe('trivy-sarif')
        ->and($sarifCheckStep['if'] ?? null)->toBe('always()')
        ->and($sarifCheckStep['run'] ?? null)
        ->toBeString()
        ->toContain('[ -s trivy-results/app.sarif ]')
        ->toContain('[ -s trivy-results/nginx.sarif ]')
        ->toContain('non_empty=true')
        ->toContain('$GITHUB_OUTPUT')
        ->and($sarifUploadStep)
        ->toBeArray()
        ->and($sarifUploadStep['if'] ?? null)
        ->toContain("steps.trivy-sarif.outputs.non_empty == 'true'")
        ->and($sarifUploadStep)->not->toHaveKey('continue-on-error')
        ->and($sarifUploadStep['with']['sarif_file'] ?? null)->toBe('trivy-results')
        ->and($sarifUploadStep['with']['category'] ?? null)->toBe('trivy-runtime-images')
        ->and($sarifArtifactStep)
        ->toBeArray()
        ->and($sarifArtifactStep['if'] ?? null)
        ->toContain("steps.trivy-sarif.outputs.non_empty == 'true'")
        ->and($sarifArtifactStep['with']['path'] ?? null)->toBe('trivy-results')
        ->and($vulnerabilityGateStep)
        ->toBeArray()
        ->and($vulnerabilityGateStep['if'] ?? null)
        ->toContain('always()')
        ->toContain("hashFiles('ernie-app-security-scan.tar') != ''")
        ->toContain("hashFiles('ernie-nginx-security-scan.tar') != ''")
        ->and($vulnerabilityGateStep['run'] ?? null)
        ->toBeString()
        ->toContain('for target in app nginx')
        ->toContain('--input "/work/ernie-${target}-security-scan.tar"')
        ->toContain('--exit-code 1')
        ->toContain('exit "$scan_status"');

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
