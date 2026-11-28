<?php

declare(strict_types=1);

use App\Services\B2inst\B2instPidResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\B2instFixture as Fixture;

covers(B2instPidResolver::class);

function configureB2instResolverCache(string $driver): void
{
    $directory = sys_get_temp_dir().'/ernie-b2inst-cache-'.bin2hex(random_bytes(8));
    $previousDriver = Cache::getDefaultDriver();
    config(['cache.stores.b2inst_test' => ['driver' => $driver, 'path' => $directory]]);
    Cache::setDefaultDriver('b2inst_test');
    expect(Cache::supportsTags())->toBe($driver === 'array');

    test()->beforeApplicationDestroyed(function () use ($directory, $previousDriver): void {
        Cache::setDefaultDriver($previousDriver);
        Cache::purge('b2inst_test');
        File::deleteDirectory($directory);
    });
}

beforeEach(function (): void {
    config(['b2inst.host' => 'https://b2inst.example.test', 'b2inst.retry_delay' => 0]);
    Http::preventStrayRequests();
});

it('clears cached positive and negative Handle results with application caches', function (string $driver, string $category, bool $registered): void {
    configureB2instResolverCache($driver);
    $record = Fixture::record();
    $handle = $record['metadata']['Identifier']['identifierValue'];
    Http::fake(function () use (&$registered, $record) {
        return $registered
            ? Http::response(Fixture::handle($record['metadata']['LandingPage']))
            : Http::response(['responseCode' => 100], 404);
    });
    $resolver = app(B2instPidResolver::class);
    for ($i = 0; $i < 2; $i++) {
        if ($registered) {
            expect($resolver->resolve($handle, null, $record))->toBe($handle);
        } else {
            expect(fn () => $resolver->resolve($handle, null, $record))->toThrow(InvalidArgumentException::class);
        }
    }
    Http::assertSentCount(1);

    $registered = true;
    $this->artisan('cache:clear-app', ['category' => $category])->assertSuccessful();
    expect($resolver->resolve($handle, null, $record))->toBe($handle);
    Http::assertSentCount(2);
})->with(['array', 'file'])->with(['all', 'vocabularies'])->with([true, false]);

it('retains the configured and bounded positive Handle lifetime', function (string $driver, int $configuredTtl, int $expectedTtl): void {
    configureB2instResolverCache($driver);
    config(['b2inst.resolver_cache_ttl' => $configuredTtl]);
    $this->freezeTime();
    $record = Fixture::record();
    $handle = $record['metadata']['Identifier']['identifierValue'];
    Http::fake(['https://hdl.handle.net/*' => Http::response(Fixture::handle($record['metadata']['LandingPage']))]);
    $resolver = app(B2instPidResolver::class);

    expect($resolver->resolve($handle, null, $record))->toBe($handle);
    $this->travel($expectedTtl - 1)->seconds();
    expect($resolver->resolve($handle, null, $record))->toBe($handle);
    Http::assertSentCount(1);
    $this->travel(1)->seconds();
    expect($resolver->resolve($handle, null, $record))->toBe($handle);
    Http::assertSentCount(2);
})->with(['array', 'file'])->with([[900, 900], [120, 120], [5000, 3600], [0, 1]]);

it('expires negative Handle results after sixty seconds independently of the positive lifetime', function (string $driver): void {
    configureB2instResolverCache($driver);
    config(['b2inst.resolver_cache_ttl' => 10]);
    $this->freezeTime();
    $record = Fixture::record();
    $handle = $record['metadata']['Identifier']['identifierValue'];
    Http::fakeSequence()->push(['responseCode' => 100], 404)->push(Fixture::handle($record['metadata']['LandingPage']));
    $resolver = app(B2instPidResolver::class);

    expect(fn () => $resolver->resolve($handle, null, $record))->toThrow(InvalidArgumentException::class);
    $this->travel(59)->seconds();
    expect(fn () => $resolver->resolve($handle, null, $record))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
    $this->travel(1)->seconds();
    expect($resolver->resolve($handle, null, $record))->toBe($handle);
    Http::assertSentCount(2);
})->with(['array', 'file']);
