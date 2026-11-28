<?php

declare(strict_types=1);

use App\Services\FundingAwardUri\CrossrefGrantSource;
use App\Services\FundingAwardUri\FunderIdentity;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(fn () => F::fake());

it('clears positive and empty source payloads without resetting provider coordination', function (string $category, bool $empty): void {
    Http::swap(new Factory);
    Http::fakeSequence()->push(['message' => ['total-results' => $empty ? 0 : 1, 'items' => $empty ? [] : [F::grant()]]])
        ->push(['message' => ['total-results' => 1, 'items' => [F::grant(doi: '10.35802/refreshed')]]]);
    $source = app(CrossrefGrantSource::class);
    $funder = new FunderIdentity('10.13039/100010269', ['10.13039/100010269']);
    expect($source->lookup('218300', $funder)->candidates)->toHaveCount($empty ? 0 : 1);
    $source->lookup('218300', $funder);
    Http::assertSentCount(1);
    $cooldown = microtime(true) + 120;
    Cache::put('award-uri:next-request:crossref', $cooldown, 300);
    $lock = Cache::lock('award-uri:http:crossref', 90);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('cache:clear-app', ['category' => $category])->assertSuccessful();
        expect(Cache::get('award-uri:next-request:crossref'))->toBe($cooldown)
            ->and(Cache::lock('award-uri:http:crossref', 90)->get())->toBeFalse();
    } finally {
        $lock->release();
        Cache::forget('award-uri:next-request:crossref');
    }
    expect($source->lookup('218300', $funder)->candidates[0]->uri)->toBe('https://doi.org/10.35802/refreshed');
    Http::assertSentCount(2);
})->with(['assistance', 'all'])->with([false, true]);

it('caches source payloads with the existing fallback on an untagged file store', function (): void {
    $directory = storage_path('framework/cache/award-uri-'.Str::uuid());
    $default = config('cache.default');
    config(['cache.default' => 'award_uri_test', 'cache.stores.award_uri_test' => ['driver' => 'file', 'path' => $directory]]);
    try {
        $source = app(CrossrefGrantSource::class);
        $funder = new FunderIdentity('10.13039/100010269', ['10.13039/100010269']);
        expect($source->lookup('218300', $funder)->candidates[0]->uri)->toBe(F::URI);
        expect($source->lookup('218300', $funder)->candidates[0]->uri)->toBe(F::URI);
        Http::assertSentCount(1);
        $this->artisan('cache:clear-app', ['category' => 'assistance'])->assertSuccessful();
        $source->lookup('218300', $funder);
        Http::assertSentCount(2);
    } finally {
        Cache::purge('award_uri_test');
        config(['cache.default' => $default]);
        File::deleteDirectory($directory);
    }
});
