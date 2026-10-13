<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Configuration;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// Keep existing paths and test identities stable. Only explicitly reviewed pure
// tests opt out of Laravel; newly added Unit tests keep the integration setup.
$pureUnitTests = json_decode(
    (string) file_get_contents(__DIR__.'/pest/pure-unit-tests.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$pureUnitPaths = [];
foreach ($pureUnitTests as $relativePath) {
    $path = realpath(__DIR__.'/pest/'.$relativePath);
    if ($path === false || ! str_starts_with($path, __DIR__.'/pest/Unit/')) {
        throw new RuntimeException('Invalid pure unit test path: '.$relativePath);
    }
    $pureUnitPaths[] = $path;
}

$frameworkUnitTargets = function (string $directory) use (&$frameworkUnitTargets, $pureUnitPaths): array {
    $hasPureTests = array_any($pureUnitPaths, fn (string $path): bool => str_starts_with($path, $directory.'/'));
    if (! $hasPureTests) {
        return [$directory];
    }

    $targets = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory.'/'.$entry;
        if (is_dir($path)) {
            array_push($targets, ...$frameworkUnitTargets($path));
        } elseif (str_ends_with($entry, 'Test.php') && ! in_array($path, $pureUnitPaths, true)) {
            $targets[] = $path;
        }
    }

    return $targets;
};

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Non-browser tests do not need the frontend bundle and should not depend
        // on a Vite dev server or built assets being present.
        $this->withoutVite();
    })
    ->in('pest/Feature', 'pest/Debug', ...$frameworkUnitTargets(__DIR__.'/pest/Unit'));

// Architecture tests need Laravel's path helpers but never a migrated database.
pest()->extend(TestCase::class)
    ->beforeEach(function () {
        $this->withoutVite();
    })
    ->in('pest/Arch');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('pest/Browser');

/*
|--------------------------------------------------------------------------
| Browser Testing Configuration
|--------------------------------------------------------------------------
|
| Configure the Pest Browser plugin for smoke testing. Uses the built-in
| PHP server provided by Laravel's testing stack.
| Only configure if the Browser plugin is available.
|
*/

if (class_exists(Configuration::class)) {
    pest()->browser()
        ->timeout(15000);  // 15s timeout for browser operations
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeValidDoi', function () {
    return $this->toBeString()
        ->toMatch('/^10\.\d{4,}\/[\w.\-\/]+$/');
});

expect()->extend('toBeValidDataCiteJson', function () {
    return $this->toBeArray()
        ->toHaveKey('data')
        ->and($this->value['data'])->toBeArray()
        ->toHaveKeys(['type', 'attributes']);
});

expect()->extend('toBeSuccessfulResponse', function () {
    return $this->status()->toBeBetween(200, 299);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

require_once __DIR__.'/pest/Helpers.php';
