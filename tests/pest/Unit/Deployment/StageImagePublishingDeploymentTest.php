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
        ->toContain("context.eventName === 'workflow_dispatch'")
        ->toContain('core.setFailed(message)');
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
    $createDeployment = $publishSteps->get('Create digest-pinned Stage deployment commit');
    $advanceDeployment = $publishSteps->get('Advance the digest-pinned Stage deployment branch');

    expect($createDeployment)
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
