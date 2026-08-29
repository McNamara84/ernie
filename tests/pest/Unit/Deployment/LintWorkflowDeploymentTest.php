<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('checks changed files with non-mutating formatters in CI', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/lint.yml'));

    expect($workflow)->toBeArray();

    $qualityJob = $workflow['jobs']['quality'] ?? null;
    $pintJob = $workflow['jobs']['pint'] ?? null;

    expect($qualityJob)
        ->toBeArray()
        ->and($pintJob)->toBeArray();

    $qualitySteps = collect($qualityJob['steps'] ?? [])->keyBy('name');
    $formatStep = $qualitySteps->get('Check Frontend formatting');

    expect($qualityJob['env']['FORMAT_BASE'] ?? null)
        ->toBeString()
        ->and($formatStep)->toBeArray()
        ->and($formatStep['run'] ?? null)
        ->toBeString()
        ->toContain('git diff --name-only')
        ->toContain('prettier --check')
        ->not->toContain('npm run format');

    $pintSteps = collect($pintJob['steps'] ?? [])->keyBy('name');
    $pintStep = $pintSteps->get('Run Pint');

    expect($pintJob['env']['FORMAT_BASE'] ?? null)
        ->toBeString()
        ->and($pintStep)->toBeArray()
        ->and($pintStep['run'] ?? null)
        ->toBeString()
        ->toContain('pint --test')
        ->toContain('--parallel')
        ->toContain('--diff="$FORMAT_BASE"');
});
