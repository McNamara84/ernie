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
