<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('runs architecture tests outside the parallel coverage workers', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toBeArray();

    $steps = $workflow['jobs']['ci']['steps'] ?? null;
    expect($steps)->toBeArray();

    $testsStep = collect($steps)->first(
        static fn (mixed $step): bool => is_array($step) && ($step['name'] ?? null) === 'Tests',
    );

    expect($testsStep)->toBeArray();

    $commands = $testsStep['run'] ?? null;
    expect($commands)
        ->toBeString()
        ->toContain('./vendor/bin/pest --group=serial --exclude-testsuite=Arch')
        ->toContain('XDEBUG_MODE=off ./vendor/bin/pest --testsuite=Arch --no-coverage')
        ->toContain('php -d memory_limit=4G ./vendor/bin/pest --parallel')
        ->toContain('--passthru-php="\'-d\' \'memory_limit=1G\'"')
        ->toContain('--exclude-group=serial --exclude-testsuite=Arch');

    $architecturePosition = strpos($commands, '--testsuite=Arch --no-coverage');
    $parallelPosition = strpos($commands, 'php -d memory_limit=4G ./vendor/bin/pest --parallel');

    expect($architecturePosition)
        ->toBeInt()
        ->toBeLessThan($parallelPosition);
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
        ->toContain('Math.min(8, Math.floor(availableParallelism() / 2))')
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
