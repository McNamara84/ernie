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
        ->toContain('php -d memory_limit=4G ./vendor/bin/pest --parallel --exclude-group=serial --exclude-testsuite=Arch');

    $architecturePosition = strpos($commands, '--testsuite=Arch --no-coverage');
    $parallelPosition = strpos($commands, 'php -d memory_limit=4G ./vendor/bin/pest --parallel');

    expect($architecturePosition)
        ->toBeInt()
        ->toBeLessThan($parallelPosition);
});
