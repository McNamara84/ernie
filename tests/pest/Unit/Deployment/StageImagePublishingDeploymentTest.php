<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('publishes Stage images only after every deployment workflow passed for the same main commit', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/publish-stage-images.yml'));

    expect($workflow)->toBeArray();

    $requiredWorkflows = [
        'Security Checks',
        'Pest PHP Unit Tests',
        'Vitest TS integration Tests',
        'Linter Tests',
        'Playwright UI Tests',
    ];

    expect($workflow['on']['workflow_run']['workflows'] ?? null)->toBe($requiredWorkflows)
        ->and($workflow['on'] ?? [])->not->toHaveKey('workflow_dispatch')
        ->and($workflow['permissions']['contents'] ?? null)->toBe('read')
        ->and($workflow['permissions'] ?? [])->not->toHaveKey('packages');

    $validateJob = $workflow['jobs']['validate'] ?? null;
    $publishJob = $workflow['jobs']['publish'] ?? null;

    expect($validateJob)
        ->toBeArray()
        ->and($validateJob['permissions']['actions'] ?? null)->toBe('read')
        ->and($validateJob['permissions']['contents'] ?? null)->toBe('read')
        ->and($publishJob)->toBeArray()
        ->and($publishJob['needs'] ?? null)->toBe('validate')
        ->and($publishJob['if'] ?? null)
        ->toContain("needs.validate.outputs.current == 'true'")
        ->toContain("needs.validate.outputs.checks_ready == 'true'")
        ->and($publishJob['permissions']['contents'] ?? null)->toBe('write')
        ->and($publishJob['permissions']['packages'] ?? null)->toBe('write');

    $validateSteps = collect($validateJob['steps'] ?? [])->keyBy('name');
    $deploymentGate = $validateSteps->get('Require successful deployment checks');

    expect($deploymentGate)
        ->toBeArray()
        ->and($deploymentGate['id'] ?? null)->toBe('deployment-checks')
        ->and($deploymentGate['with']['script'] ?? null)
        ->toBeString()
        ->toContain("['security.yml', 'Security Checks']")
        ->toContain("['tests.yml', 'Pest PHP Unit Tests']")
        ->toContain("['vitest.yml', 'Vitest TS integration Tests']")
        ->toContain("['lint.yml', 'Linter Tests']")
        ->toContain("['playwright.yml', 'Playwright UI Tests']")
        ->toContain("branch: 'main'")
        ->toContain("event: 'push'")
        ->toContain('head_sha: sourceSha')
        ->toContain("result.status === 'completed' && result.conclusion === 'success'")
        ->not->toContain('workflow_dispatch')
        ->toContain('core.notice(message)');

    expect($validateJob['if'] ?? null)
        ->toContain("github.event.workflow_run.event == 'push'")
        ->toContain("github.event.workflow_run.conclusion == 'success'")
        ->not->toContain('workflow_dispatch')
        ->and($validateSteps->get('Checkout the candidate main commit')['with']['ref'] ?? null)
        ->toBe('${{ github.event.workflow_run.head_sha }}');
});

it('builds images from sanitized Production defaults without replacing their runtime settings', function (): void {
    $publishWorkflow = Yaml::parseFile(base_path('.github/workflows/publish-stage-images.yml'));
    $securityWorkflow = Yaml::parseFile(base_path('.github/workflows/security.yml'));
    $productionEnvironment = file_get_contents(base_path('.env.production'));
    $validator = file_get_contents(base_path('scripts/validate-production-environment.sh'));

    expect($publishWorkflow)->toBeArray()
        ->and($securityWorkflow)->toBeArray()
        ->and($productionEnvironment)->toBeString()
        ->toMatch('/^LOG_STACK=daily$/m')
        ->toMatch('/^LOG_LEVEL=error$/m')
        ->and($validator)->toBeString()
        ->toContain('_(PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY|ENCRYPTION_KEY|SIGNING_KEY|AUTH|CREDENTIAL|CREDENTIALS)')
        ->toContain('invalid_keys+=("$key")')
        ->toContain('printf \'  - %s\\n\' "${invalid_keys[@]}"');

    foreach ([
        $publishWorkflow['jobs']['publish']['steps'] ?? [],
        $securityWorkflow['jobs']['container-scan']['steps'] ?? [],
    ] as $steps) {
        $namedSteps = collect($steps)->keyBy('name');
        $validation = $namedSteps->get('Validate public production environment');

        expect($validation)->toBeArray()
            ->and($validation['run'] ?? null)->toBe('bash scripts/validate-production-environment.sh')
            ->and($namedSteps->has('Replace production environment with public build defaults'))->toBeFalse();
    }
});

it('publishes a digest-pinned Stage deployment with a compare-and-swap branch update', function (): void {
    $workflowContents = file_get_contents(base_path('.github/workflows/publish-stage-images.yml'));
    $composeContents = file_get_contents(base_path('docker-compose.stage.yml'));
    $workflow = Yaml::parseFile(base_path('.github/workflows/publish-stage-images.yml'));

    expect($workflowContents)->toBeString()
        ->and($composeContents)->toBeString()
        ->and(substr_count($composeContents, 'ghcr.io/mcnamara84/ernie-app:deployment-template'))->toBe(4)
        ->and(substr_count($composeContents, 'ghcr.io/mcnamara84/ernie-nginx:deployment-template'))->toBe(1)
        ->and($composeContents)
        ->not->toContain('ghcr.io/mcnamara84/ernie-app:stage')
        ->not->toContain('ghcr.io/mcnamara84/ernie-nginx:stage')
        ->and($workflowContents)->not->toContain('docker buildx imagetools create --tag');

    $publishSteps = collect($workflow['jobs']['publish']['steps'] ?? [])->keyBy('name');
    $prepareTrivyCache = $publishSteps->get('Prepare Trivy cache');
    $publishedDigestScan = $publishSteps->get('Scan exact published image digests');
    $createDeployment = $publishSteps->get('Create digest-pinned Stage deployment commit');
    $advanceDeployment = $publishSteps->get('Advance the digest-pinned Stage deployment branch');

    expect($workflowContents)
        ->not->toContain('uses: actions/cache@')
        ->and($prepareTrivyCache)
        ->toBeArray()
        ->and($prepareTrivyCache['run'] ?? null)
        ->toBe('mkdir -p "${{ runner.temp }}/trivy-cache"')
        ->and($publishedDigestScan)
        ->toBeArray()
        ->and($publishedDigestScan['env']['APP_IMAGE_REF'] ?? null)
        ->toBe('${{ needs.validate.outputs.app_image }}@${{ steps.app-build.outputs.digest }}')
        ->and($publishedDigestScan['env']['NGINX_IMAGE_REF'] ?? null)
        ->toBe('${{ needs.validate.outputs.nginx_image }}@${{ steps.nginx-build.outputs.digest }}')
        ->and($publishedDigestScan['env']['TRIVY_PASSWORD'] ?? null)
        ->toBe('${{ secrets.GITHUB_TOKEN }}')
        ->and($publishedDigestScan['run'] ?? null)
        ->toBeString()
        ->toContain('for image_ref in "$APP_IMAGE_REF" "$NGINX_IMAGE_REF"')
        ->toContain('@sha256:[0-9a-f]{64}$')
        ->toContain('aquasec/trivy:0.74.0@sha256:')
        ->toContain('-v "${{ runner.temp }}/trivy-cache:/root/.cache/trivy"')
        ->toContain('--cache-dir /root/.cache/trivy')
        ->toContain('--exit-code 1')
        ->toContain('--severity CRITICAL,HIGH')
        ->toContain('"$image_ref" || scan_status=1')
        ->toContain('exit "$scan_status"')
        ->and($createDeployment)
        ->toBeArray()
        ->and($createDeployment['id'] ?? null)->toBe('deployment')
        ->and($createDeployment['if'] ?? null)->toBe("steps.latest.outputs.current == 'true'")
        ->and($createDeployment['run'] ?? null)
        ->toBeString()
        ->toContain('^sha256:[0-9a-f]{64}$')
        ->toContain('APP_TEMPLATE="${APP_IMAGE}:deployment-template"')
        ->toContain('NGINX_TEMPLATE="${NGINX_IMAGE}:deployment-template"')
        ->toContain('docker compose -f docker-compose.stage.yml config --quiet')
        ->toContain('git ls-remote --heads origin refs/heads/deploy/stage')
        ->toContain('PARENTS=(-p "$PREVIOUS_DEPLOY_SHA")')
        ->toContain('PARENTS+=(-p "$SOURCE_SHA")')
        ->toContain('git commit-tree "$DEPLOY_TREE" "${PARENTS[@]}"')
        ->toContain('echo "commit=$DEPLOY_COMMIT"')
        ->toContain('echo "previous=$PREVIOUS_DEPLOY_SHA"');

    expect($advanceDeployment)
        ->toBeArray()
        ->and($advanceDeployment['if'] ?? null)->toBe("steps.latest.outputs.current == 'true'")
        ->and($advanceDeployment['env']['DEPLOY_COMMIT'] ?? null)
        ->toBe('${{ steps.deployment.outputs.commit }}')
        ->and($advanceDeployment['env']['PREVIOUS_DEPLOY_SHA'] ?? null)
        ->toBe('${{ steps.deployment.outputs.previous }}')
        ->and($advanceDeployment['run'] ?? null)
        ->toBeString()
        ->toContain('refs/heads/main:refs/remotes/origin/main')
        ->toContain('--force-with-lease=refs/heads/deploy/stage:${PREVIOUS_DEPLOY_SHA}')
        ->toContain('--force-with-lease=refs/heads/deploy/stage:')
        ->toContain('${DEPLOY_COMMIT}:refs/heads/deploy/stage');

    $publishStepNames = collect($workflow['jobs']['publish']['steps'] ?? [])->pluck('name')->values();
    $nginxBuildPosition = $publishStepNames->search('Build and push Nginx image');
    $digestScanPosition = $publishStepNames->search('Scan exact published image digests');
    $deploymentPosition = $publishStepNames->search('Create digest-pinned Stage deployment commit');

    expect($nginxBuildPosition)->toBeInt()
        ->and($digestScanPosition)->toBeInt()->toBeGreaterThan($nginxBuildPosition)
        ->and($deploymentPosition)->toBeInt()->toBeGreaterThan($digestScanPosition);
});

it('documents that direct Stage Compose starts require the generated deployment branch', function (): void {
    $documentation = file_get_contents(base_path('docs/production-runtime-performance.md'));

    expect($documentation)
        ->toBeString()
        ->toContain('valid only from a checkout of the generated `deploy/stage` branch')
        ->toMatch('/Do not run\s+it from `main`/')
        ->toMatch('/unpublished\s+`deployment-template` image tags/')
        ->toContain('docker compose -f docker-compose.stage.yml up -d');
});

it('retains the null Redis password sentinel for unauthenticated Production Redis', function (): void {
    $environment = file_get_contents(base_path('.env.production'));
    $compose = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    expect($environment)
        ->toBeString()
        ->toMatch('/^REDIS_PASSWORD=null$/m')
        ->and($compose)->toBeArray()
        ->and($compose['services']['redis'] ?? null)->toBeArray()
        ->and($compose['services']['redis'] ?? [])->not->toHaveKey('command');

    foreach (['app', 'queue', 'assessment-queue', 'scheduler'] as $serviceName) {
        $serviceEnvironment = $compose['services'][$serviceName]['environment'] ?? [];

        expect($serviceEnvironment)->toBeArray();

        $redisPasswordOverrides = array_filter(
            $serviceEnvironment,
            static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, 'REDIS_PASSWORD='),
        );

        expect($redisPasswordOverrides)->toBeEmpty();
    }
});
