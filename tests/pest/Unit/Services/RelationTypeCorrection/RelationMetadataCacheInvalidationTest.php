<?php

declare(strict_types=1);

use App\Services\RelationTypeCorrection\RelationMetadataClientService;
use App\Services\RelationTypeCorrection\RelationSupplementaryClientService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function fakeRelationCacheMetadata(string &$relation): void
{
    Http::fake(function (Request $request) use (&$relation) {
        if (str_contains($request->url(), '/events')) {
            return Http::response(['data' => [['attributes' => [
                'subj-id' => '10.5880/cache.a',
                'obj-id' => '10.5880/cache.b',
                'relation-type-id' => $relation,
                'source-id' => 'datacite-related',
            ]]], 'links' => ['next' => null]]);
        }
        if (str_contains($request->url(), '/Links')) {
            return Http::response(['result' => [[
                'source' => ['Identifier' => [['ID' => '10.5880/cache.a']]],
                'target' => ['Identifier' => [['ID' => '10.5880/cache.b']]],
                'RelationshipType' => ['Name' => $relation],
            ]], 'totalPages' => 1]);
        }

        return Http::response([
            'data' => ['attributes' => ['doi' => '10.5880/cache.a', 'version' => $relation]],
            'message' => ['DOI' => '10.5880/cache.a', 'version' => $relation],
        ]);
    });
}

it('refreshes raw relation metadata after assistance or relation correction invalidation', function (string $tag): void {
    $relation = 'HasPart';
    fakeRelationCacheMetadata($relation);
    $client = app(RelationMetadataClientService::class);

    foreach (['datacite', 'crossref'] as $provider) {
        expect($client->record($provider, '10.5880/cache.a')['metadata']['version'])->toBe('HasPart');
        $client->record($provider, '10.5880/cache.a');
    }
    Http::assertSentCount(2);
    $relation = 'IsDerivedFrom';

    if ($tag === 'assistance') {
        $this->artisan('cache:clear-app', ['category' => $tag])->assertSuccessful();
    } else {
        Cache::tags([$tag])->flush();
    }

    foreach (['datacite', 'crossref'] as $provider) {
        expect($client->record($provider, '10.5880/cache.a')['metadata']['version'])->toBe('IsDerivedFrom');
    }
    Http::assertSentCount(4);
})->with(['assistance', 'relation_correction']);

it('refreshes supplementary relation evidence after assistance or relation correction invalidation', function (string $tag): void {
    $relation = 'HasPart';
    fakeRelationCacheMetadata($relation);
    $client = app(RelationSupplementaryClientService::class);
    $result = $client->forPair('10.5880/cache.a', '10.5880/cache.b');
    expect(array_column($result['evidence'], 'relation'))->toBe(['HasPart', 'HasPart'])
        ->and($client->forPair('10.5880/cache.a', '10.5880/cache.b'))->toEqual($result);
    Http::assertSentCount(2);
    $relation = 'IsDerivedFrom';

    if ($tag === 'assistance') {
        $this->artisan('cache:clear-app', ['category' => $tag])->assertSuccessful();
    } else {
        Cache::tags([$tag])->flush();
    }

    $result = $client->forPair('10.5880/cache.a', '10.5880/cache.b');
    expect(array_column($result['evidence'], 'relation'))->toBe(['IsDerivedFrom', 'IsDerivedFrom']);
    Http::assertSentCount(4);
})->with(['assistance', 'relation_correction']);

it('caches and refreshes both relation clients with a file store that does not support tags', function (): void {
    $directory = sys_get_temp_dir().'/ernie-relation-cache-'.bin2hex(random_bytes(8));
    $defaultDriver = Cache::getDefaultDriver();
    config(['cache.stores.relation_correction_test' => ['driver' => 'file', 'path' => $directory]]);
    Cache::setDefaultDriver('relation_correction_test');

    try {
        expect(Cache::supportsTags())->toBeFalse();
        $relation = 'HasPart';
        fakeRelationCacheMetadata($relation);
        $primary = app(RelationMetadataClientService::class);
        $supplementary = app(RelationSupplementaryClientService::class);

        foreach (['datacite', 'crossref'] as $provider) {
            $record = $primary->record($provider, '10.5880/cache.a');
            expect($record['metadata']['version'])->toBe('HasPart')
                ->and($primary->record($provider, '10.5880/cache.a'))->toBe($record);
        }
        $result = $supplementary->forPair('10.5880/cache.a', '10.5880/cache.b');
        expect(array_column($result['evidence'], 'relation'))->toBe(['HasPart', 'HasPart'])
            ->and($supplementary->forPair('10.5880/cache.a', '10.5880/cache.b'))->toEqual($result);
        Http::assertSentCount(4);

        $relation = 'IsDerivedFrom';
        $this->artisan('cache:clear-app', ['category' => 'assistance'])->assertSuccessful();
        foreach (['datacite', 'crossref'] as $provider) {
            expect($primary->record($provider, '10.5880/cache.a')['metadata']['version'])->toBe('IsDerivedFrom');
        }
        $result = $supplementary->forPair('10.5880/cache.a', '10.5880/cache.b');
        expect(array_column($result['evidence'], 'relation'))->toBe(['IsDerivedFrom', 'IsDerivedFrom']);
        Http::assertSentCount(8);
    } finally {
        Cache::setDefaultDriver($defaultDriver);
        Cache::purge('relation_correction_test');
        File::deleteDirectory($directory);
    }
});
