<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('uses an unprivileged published-release signal for Production promotion', function (): void {
    $signal = Yaml::parseFile(base_path('.github/workflows/production-release-signal.yml'));

    expect($signal)->toBeArray()
        ->and($signal['on']['release']['types'] ?? null)->toBe(['published'])
        ->and($signal['on'] ?? [])->not->toHaveKey('workflow_dispatch')
        ->and($signal['permissions']['contents'] ?? null)->toBe('read')
        ->and($signal['permissions'] ?? [])->not->toHaveKeys(['packages', 'actions'])
        ->and($signal['jobs']['signal']['steps'][0]['name'] ?? null)
        ->toBe('Record the unprivileged release signal');
});

it('loads the privileged Production workflow from the default branch', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/promote-production-release.yml'));

    expect($workflow)->toBeArray()
        ->and($workflow['on']['workflow_run']['workflows'] ?? null)
        ->toBe(['Production Release Signal'])
        ->and($workflow['on']['workflow_run']['types'] ?? null)->toBe(['completed'])
        ->and($workflow['on'] ?? [])->not->toHaveKeys(['release', 'workflow_dispatch'])
        ->and($workflow['permissions']['contents'] ?? null)->toBe('read')
        ->and($workflow['permissions'] ?? [])->not->toHaveKey('packages')
        ->and($workflow['concurrency']['cancel-in-progress'] ?? null)->toBeTrue();

    $validateJob = $workflow['jobs']['validate'] ?? null;
    $inspectJob = $workflow['jobs']['inspect'] ?? null;
    $promoteJob = $workflow['jobs']['promote'] ?? null;

    expect($validateJob)->toBeArray()
        ->and($validateJob['if'] ?? null)
        ->toContain("github.event.workflow_run.event == 'release'")
        ->toContain("github.event.workflow_run.conclusion == 'success'")
        ->and($validateJob['permissions']['actions'] ?? null)->toBe('read')
        ->and($validateJob['permissions']['contents'] ?? null)->toBe('read')
        ->and($inspectJob)->toBeArray()
        ->and($inspectJob['needs'] ?? null)->toBe('validate')
        ->and($inspectJob['if'] ?? null)
        ->toContain("needs.validate.outputs.current == 'true'")
        ->toContain("needs.validate.outputs.checks_ready == 'true'")
        ->and($inspectJob['permissions']['contents'] ?? null)->toBe('read')
        ->and($inspectJob['permissions']['packages'] ?? null)->toBe('read')
        ->and($promoteJob)->toBeArray()
        ->and($promoteJob['needs'] ?? null)->toBe(['validate', 'inspect'])
        ->and($promoteJob['if'] ?? null)
        ->toContain("needs.inspect.outputs.current == 'true'")
        ->and($promoteJob['permissions']['contents'] ?? null)->toBe('write')
        ->and($promoteJob['permissions'] ?? [])->not->toHaveKey('packages');
});

it('accepts only the latest stable semantic release on validated main history', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/promote-production-release.yml'));
    $validateSteps = collect($workflow['jobs']['validate']['steps'] ?? [])->keyBy('name');
    $release = $validateSteps->get('Resolve the latest stable release');
    $source = $validateSteps->get('Resolve and validate the release source');
    $deploymentChecks = $validateSteps->get('Require successful deployment checks');

    expect($release)->toBeArray()
        ->and($release['with']['script'] ?? null)
        ->toBeString()
        ->toContain('github.rest.repos.getLatestRelease')
        ->toContain('release.draft || release.prerelease || !stableTag')
        ->toContain('/^v\\d+\\.\\d+\\.\\d+$/')
        ->and($source)->toBeArray()
        ->and($source['env']['TRIGGER_SHA'] ?? null)
        ->toBe('${{ github.event.workflow_run.head_sha }}')
        ->and($source['run'] ?? null)
        ->toBeString()
        ->toContain('git merge-base --is-ancestor "$SOURCE_SHA" refs/remotes/origin/main')
        ->toContain('[[ "$SOURCE_SHA" == "$TRIGGER_SHA" ]]')
        ->and($deploymentChecks)->toBeArray()
        ->and($deploymentChecks['with']['script'] ?? null)
        ->toBeString()
        ->toContain("['security.yml', 'Security Checks']")
        ->toContain("['tests.yml', 'Pest PHP Unit Tests']")
        ->toContain("['vitest.yml', 'Vitest TS integration Tests']")
        ->toContain("['lint.yml', 'Linter Tests']")
        ->toContain("['playwright.yml', 'Playwright UI Tests']")
        ->toContain("branch: 'main'")
        ->toContain("event: 'push'")
        ->toContain('head_sha: sourceSha')
        ->toContain("result.status === 'completed' && result.conclusion === 'success'");
});

it('promotes the exact digest pair previously deployed to Stage', function (): void {
    $workflowContents = file_get_contents(base_path('.github/workflows/promote-production-release.yml'));
    $workflow = Yaml::parseFile(base_path('.github/workflows/promote-production-release.yml'));
    $steps = collect($workflow['jobs']['inspect']['steps'] ?? [])->keyBy('name');
    $artifacts = $steps->get('Resolve the exact Stage deployment artifacts');
    $scan = $steps->get('Scan the exact promoted image digests');

    expect($workflowContents)->toBeString()
        ->not->toContain('uses: docker/build-push-action@')
        ->not->toContain('docker buildx imagetools create')
        ->and($artifacts)->toBeArray()
        ->and($artifacts['run'] ?? null)
        ->toBeString()
        ->toContain('+refs/heads/deploy/stage:refs/remotes/origin/deploy/stage')
        ->toContain('Deploy Stage from $SOURCE_SHA')
        ->toContain('SOURCE_IS_PARENT')
        ->toContain('git show "${STAGE_DEPLOY_COMMIT}:docker-compose.stage.yml"')
        ->toContain('docker compose -f "$STAGE_COMPOSE" config --format json')
        ->toContain("'.services.app.image'")
        ->toContain("'.services.webserver.image'")
        ->toContain('for service in queue assessment-queue scheduler')
        ->toContain('docker buildx imagetools inspect "$APP_REF"')
        ->toContain('docker buildx imagetools inspect "$NGINX_REF"')
        ->and($scan)->toBeArray()
        ->and($scan['env']['APP_IMAGE_REF'] ?? null)
        ->toBe('${{ needs.validate.outputs.app_image }}@${{ steps.artifacts.outputs.app_digest }}')
        ->and($scan['env']['NGINX_IMAGE_REF'] ?? null)
        ->toBe('${{ needs.validate.outputs.nginx_image }}@${{ steps.artifacts.outputs.nginx_digest }}')
        ->and($scan['run'] ?? null)
        ->toBeString()
        ->toContain('aquasec/trivy:0.74.0@sha256:')
        ->toContain('--exit-code 1')
        ->toContain('--severity CRITICAL,HIGH');
});

it('publishes a latest-release-only digest-pinned Production deployment branch', function (): void {
    $workflowContents = file_get_contents(base_path('.github/workflows/promote-production-release.yml'));
    $workflow = Yaml::parseFile(base_path('.github/workflows/promote-production-release.yml'));
    $inspectSteps = collect($workflow['jobs']['inspect']['steps'] ?? [])->keyBy('name');
    $promoteSteps = collect($workflow['jobs']['promote']['steps'] ?? [])->keyBy('name');
    $latest = $inspectSteps->get('Confirm that the release is still latest');
    $inspectCheckout = $inspectSteps->get('Checkout the trusted default branch');
    $promoteCheckout = $promoteSteps->get('Checkout the trusted default branch');
    $deployment = $promoteSteps->get('Create the digest-pinned Production deployment commit');
    $advance = $promoteSteps->get('Advance the digest-pinned Production deployment branch');

    expect($workflowContents)->toBeString()
        ->not->toContain('ref: ${{ needs.validate.outputs.sha }}')
        ->and($inspectCheckout)->toBeArray()
        ->and($inspectCheckout['with']['ref'] ?? null)->toBe('refs/heads/main')
        ->and($promoteCheckout)->toBeArray()
        ->and($promoteCheckout['with']['ref'] ?? null)->toBe('refs/heads/main')
        ->and($latest)->toBeArray()
        ->and($latest['run'] ?? null)
        ->toBeString()
        ->toContain('releases/latest')
        ->toContain('LATEST_RELEASE_ID')
        ->toContain('LATEST_RELEASE_TAG')
        ->toContain('LATEST_SOURCE_SHA')
        ->and($deployment)->toBeArray()
        ->and($deployment)->not->toHaveKey('if')
        ->and($deployment['env']['APP_DIGEST'] ?? null)
        ->toBe('${{ needs.inspect.outputs.app_digest }}')
        ->and($deployment['env']['NGINX_DIGEST'] ?? null)
        ->toBe('${{ needs.inspect.outputs.nginx_digest }}')
        ->and($deployment['run'] ?? null)
        ->toBeString()
        ->toContain('APP_TEMPLATE="${APP_IMAGE}:deployment-template"')
        ->toContain('NGINX_TEMPLATE="${NGINX_IMAGE}:deployment-template"')
        ->toContain('git show "${SOURCE_SHA}:docker-compose.prod.yml" > "$PROD_COMPOSE"')
        ->toContain('docker compose -f "$PROD_COMPOSE" config --quiet')
        ->toContain('git hash-object -w "$PROD_COMPOSE"')
        ->toContain('git read-tree "${SOURCE_SHA}^{tree}"')
        ->toContain('git update-index --add --cacheinfo')
        ->toContain('refs/heads/deploy/prod')
        ->toContain('PARENTS=(-p "$PREVIOUS_DEPLOY_SHA")')
        ->toContain('PARENTS+=(-p "$SOURCE_SHA")')
        ->toContain('git commit-tree "$DEPLOY_TREE" "${PARENTS[@]}"')
        ->toContain('Deploy Production $RELEASE_TAG from $SOURCE_SHA')
        ->and($advance)->toBeArray()
        ->and($advance)->not->toHaveKey('if')
        ->and($advance['run'] ?? null)
        ->toBeString()
        ->toContain('releases/latest')
        ->toContain('refs/heads/main:refs/remotes/origin/main')
        ->toContain('--force-with-lease=refs/heads/deploy/prod:${PREVIOUS_DEPLOY_SHA}')
        ->toContain('--force-with-lease=refs/heads/deploy/prod:')
        ->toContain('${DEPLOY_COMMIT}:refs/heads/deploy/prod');
});

it('uses Production image templates only as workflow input', function (): void {
    $contents = file_get_contents(base_path('docker-compose.prod.yml'));
    $compose = Yaml::parseFile(base_path('docker-compose.prod.yml'));

    expect($contents)->toBeString()
        ->and(substr_count($contents, 'ghcr.io/mcnamara84/ernie-app:deployment-template'))->toBe(4)
        ->and(substr_count($contents, 'ghcr.io/mcnamara84/ernie-nginx:deployment-template'))->toBe(1)
        ->and($compose)->toBeArray();

    foreach (['app', 'queue', 'assessment-queue', 'scheduler', 'webserver'] as $serviceName) {
        $service = $compose['services'][$serviceName] ?? null;

        expect($service)->toBeArray()
            ->and($service)->not->toHaveKey('build')
            ->and($service['pull_policy'] ?? null)->toBe('always');
    }
});

it('documents the one-time and recurring Production release procedure', function (): void {
    $documentation = file_get_contents(base_path('docs/production-container-deployment.md'));

    expect($documentation)->toBeString()
        ->toContain('refs/heads/deploy/prod')
        ->toContain('docker-compose.prod.yml')
        ->toContain('Production Release Signal')
        ->toContain('Promote Production Release')
        ->toContain('v1.0.9')
        ->toContain('v1.0.8')
        ->toContain('latest stable')
        ->toContain('Pull and redeploy')
        ->toContain('ERNIE_PROD_APP_IMAGE')
        ->toContain('ERNIE_PROD_NGINX_IMAGE');
});
