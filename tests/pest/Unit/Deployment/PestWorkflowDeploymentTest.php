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

    $iniValues = $setupPhpStep['with']['ini-values'] ?? null;
    expect($iniValues)->toBeString();
    assert(is_string($iniValues));

    $parseIniValues = static function (string $values): array {
        $entries = preg_split('/,\s*/', $values);
        assert(is_array($entries));

        $configuration = parse_ini_string(implode(PHP_EOL, $entries), scanner_mode: INI_SCANNER_RAW);
        assert(is_array($configuration));

        return $configuration;
    };

    $iniConfiguration = $parseIniValues($iniValues);

    foreach ([',', ',   '] as $separator) {
        $formattingVariant = preg_replace('/,\s*/', $separator, $iniValues);
        assert(is_string($formattingVariant));

        expect($parseIniValues($formattingVariant))->toBe($iniConfiguration);
    }

    expect($iniConfiguration['memory_limit'] ?? null)->toBe('1G')
        ->and($iniConfiguration['pcov.directory'] ?? null)->toBe('.')
        ->and($iniConfiguration['pcov.exclude'] ?? null)->toBe('~/(?:vendor|tests)/~');

    $pcovExclude = $iniConfiguration['pcov.exclude'] ?? null;
    assert(is_string($pcovExclude));

    expect(preg_match($pcovExclude, '/workspace/vendor/package/file.php'))->toBe(1)
        ->and(preg_match($pcovExclude, '/workspace/tests/pest/Test.php'))->toBe(1)
        ->and(preg_match($pcovExclude, '/workspace/app/Models/User.php'))->toBe(0);

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
        ->toStartWith('php -d memory_limit=4G ')
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

it('pins local MySQL-backed development and tests to the 9.7 server series', function (): void {
    $compose = Yaml::parseFile(base_path('docker-compose.dev.yml'));

    expect($compose)->toBeArray();

    $database = $compose['services']['db'] ?? null;
    expect($database)
        ->toBeArray()
        ->and($database['image'] ?? null)
        ->toBeString()
        ->toStartWith('mysql:9.7.')
        ->and($database['volumes'] ?? null)
        ->toContain('ernie-db-data-mysql-9-7:/var/lib/mysql')
        ->and($database['healthcheck']['test'] ?? null)
        ->toBeArray()
        ->toContain("mysqladmin ping -h localhost -u root -prootsecret --silent && mysql -h localhost -u root -prootsecret -Nse 'SELECT VERSION()' | grep -Eq '^9\\.7\\.'");
});
