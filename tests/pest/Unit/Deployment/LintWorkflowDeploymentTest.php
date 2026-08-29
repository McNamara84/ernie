<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('checks the complete codebase with non-mutating formatters in CI', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/lint.yml'));

    expect($workflow)->toBeArray();

    $qualityJob = $workflow['jobs']['quality'] ?? null;
    $pintJob = $workflow['jobs']['pint'] ?? null;

    expect($qualityJob)
        ->toBeArray()
        ->and($pintJob)->toBeArray();

    $qualitySteps = collect($qualityJob['steps'] ?? [])->keyBy('name');
    $formatStep = $qualitySteps->get('Check Frontend formatting');

    expect($formatStep)
        ->toBeArray()
        ->and($formatStep['run'] ?? null)
        ->toBe('npm run format:check');

    $pintSteps = collect($pintJob['steps'] ?? [])->keyBy('name');
    $pintStep = $pintSteps->get('Run Pint');

    expect($pintStep)
        ->toBeArray()
        ->and($pintStep['run'] ?? null)
        ->toBeString()
        ->toContain('pint --test')
        ->toContain('--parallel')
        ->not->toContain('--diff');
});
