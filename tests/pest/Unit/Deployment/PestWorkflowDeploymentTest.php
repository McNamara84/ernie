<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('splits CI tests into isolated coverage and architecture slices', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toBeArray();

    $pestJob = $workflow['jobs']['pest'] ?? null;
    expect($pestJob)->toBeArray();

    $matrix = $pestJob['strategy']['matrix']['include'] ?? null;
    expect($matrix)->toBeArray();

    $slices = collect($matrix)->keyBy('slice');
    expect($slices->keys()->all())
        ->toBe(['serial', 'architecture', 'parallel-1', 'parallel-2'])
        ->and($slices->get('serial')['coverage'] ?? null)->toBe('pcov')
        ->and($slices->get('architecture')['coverage'] ?? null)->toBe('none')
        ->and($slices->get('parallel-1')['shard'] ?? null)->toBe('1/2')
        ->and($slices->get('parallel-2')['shard'] ?? null)->toBe('2/2');

    $steps = collect($pestJob['steps'] ?? [])->keyBy('name');

    $setupPhpStep = $steps->get('Setup PHP');
    $serialStep = $steps->get('Run serial tests with coverage');
    $architectureStep = $steps->get('Run architecture tests');
    $parallelStep = $steps->get('Run parallel test shard with coverage');

    expect($setupPhpStep)
        ->toBeArray()
        ->and($serialStep)
        ->toBeArray()
        ->and($architectureStep)->toBeArray()
        ->and($parallelStep)->toBeArray();

    expect($setupPhpStep['with']['ini-values'] ?? null)
        ->toBeString()
        ->toContain('pcov.directory=.')
        ->toContain('pcov.exclude=')
        ->toContain('vendor')
        ->toContain('tests');

    expect($serialStep['run'] ?? null)
        ->toBeString()
        ->toContain('--group=serial')
        ->toContain('--exclude-testsuite=Arch')
        ->and($architectureStep['run'] ?? null)
        ->toBeString()
        ->toContain('--testsuite=Arch')
        ->toContain('--no-coverage')
        ->not->toContain('--parallel')
        ->and($parallelStep['run'] ?? null)
        ->toBeString()
        ->toContain('--parallel')
        ->toContain('--shard=${{ matrix.shard }}')
        ->toContain('--exclude-group=serial')
        ->toContain('--exclude-testsuite=Arch');

    $coverageJob = $workflow['jobs']['coverage'] ?? null;
    expect($coverageJob)
        ->toBeArray()
        ->and($coverageJob['needs'] ?? null)->toBe('pest');

    $coverageSteps = collect($coverageJob['steps'] ?? [])->keyBy('name');
    $uploadStep = $coverageSteps->get('Upload coverage to Codecov');

    expect($uploadStep)
        ->toBeArray()
        ->and($uploadStep['with']['files'] ?? null)
        ->toBeString()
        ->toContain('coverage-serial.xml')
        ->toContain('coverage-parallel-1.xml')
        ->toContain('coverage-parallel-2.xml');
});

it('keeps local backend validation parallel, Linux-native, and on the two gigabyte memory floor', function (): void {
    $runner = file_get_contents(base_path('scripts/run-pest.mjs'));
    $workspacePreparation = file_get_contents(base_path('scripts/prepare-pest-workspace.sh'));
    $testBootstrap = file_get_contents(base_path('tests/pest/CreatesApplication.php'));
    $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($runner)
        ->toBeString()
        ->toContain("const phpMemoryLimit = '2G';")
        ->toContain("const pestWorkspace = '/var/www/pest-workspace';")
        ->toContain('Math.max(1, Math.min(8, Math.floor(availableParallelism() / 2)))')
        ->toContain("'--parallel'")
        ->toContain("'--no-progress'")
        ->toContain("'--exclude-group=serial'")
        ->toContain("'--exclude-testsuite=Arch'")
        ->and($workspacePreparation)
        ->toBeString()
        ->toContain('TEST_WORKSPACE=/var/www/pest-workspace')
        ->toContain("--exclude='./vendor'")
        ->and($testBootstrap)
        ->toBeString()
        ->toContain("'CACHE_STORE' => 'array'")
        ->toContain("'SESSION_DRIVER' => 'array'")
        ->and($package['scripts']['phpstan:check'] ?? null)
        ->toBeString()
        ->toContain('php -d memory_limit=2G')
        ->toContain('--memory-limit=2G')
        ->and($package['scripts']['test:php:mysql-sensitive:exec'] ?? null)
        ->toBeString()
        ->toContain('php -d memory_limit=2G');
});
