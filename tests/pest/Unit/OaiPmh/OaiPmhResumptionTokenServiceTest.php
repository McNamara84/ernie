<?php

declare(strict_types=1);

use App\Models\OaiPmhHarvest;
use App\Models\OaiPmhResumptionToken;
use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('create', function () {
    it('creates a token with correct attributes', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        $token = $service->create(
            verb: 'ListRecords',
            metadataPrefix: 'oai_dc',
            setSpec: 'resourcetype:dataset',
            from: null,
            until: null,
            cursor: 100,
            completeListSize: 500,
        );

        expect($token)->toBeInstanceOf(OaiPmhResumptionToken::class)
            ->and($token->verb)->toBe('ListRecords')
            ->and($token->metadata_prefix)->toBe('oai_dc')
            ->and($token->set_spec)->toBe('resourcetype:dataset')
            ->and($token->cursor)->toBe(100)
            ->and($token->complete_list_size)->toBe(500)
            ->and($token->token)->toHaveLength(64)
            ->and($token->expires_at)->toBeInstanceOf(Carbon::class);
    });
});

it('rejects a token with an expired harvest and purges snapshot identities', function () {
    $service = app(OaiPmhResumptionTokenService::class);
    $harvest = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
    DB::table('oai_pmh_harvest_items')->insert([
        'harvest_id' => $harvest->id, 'position' => 0, 'kind' => 'resource', 'identity_id' => 123,
    ]);
    $first = $service->create('ListRecords', 'oai_dc', 'epos-msl', null, null, 1, 5, $harvest);
    $second = $service->create('ListRecords', 'oai_dc', 'epos-msl', null, null, 2, 5, $harvest);
    $harvest->update(['expires_at' => now()->subHour()]);
    expect($service->resolve($first->token))->toBeNull();
    $service->purgeExpired();
    expect(OaiPmhHarvest::find($harvest->id))->toBeNull()
        ->and(DB::table('oai_pmh_harvest_items')->count())->toBe(0)
        ->and(OaiPmhResumptionToken::find($second->id))->toBeNull();
});

describe('resolve', function () {
    it('resolves a valid token', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        $created = $service->create('ListRecords', 'oai_dc', null, null, null, 0, 100);
        $resolved = $service->resolve($created->token);

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($created->id);
    });

    it('returns null for nonexistent token', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        expect($service->resolve('nonexistent_token'))->toBeNull();
    });

    it('returns null and deletes expired token', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        $token = $service->create('ListRecords', 'oai_dc', null, null, null, 0, 100);
        $token->update(['expires_at' => now()->subHour()]);

        $resolved = $service->resolve($token->token);

        expect($resolved)->toBeNull()
            ->and(OaiPmhResumptionToken::find($token->id))->toBeNull();
    });
});

describe('consume', function () {
    it('deletes the token after consumption', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        $token = $service->create('ListRecords', 'oai_dc', null, null, null, 0, 100);
        $service->consume($token);

        expect(OaiPmhResumptionToken::find($token->id))->toBeNull();
    });
});

describe('purgeExpired', function () {
    it('deletes all expired tokens', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        // Create expired tokens
        $service->create('ListRecords', 'oai_dc', null, null, null, 0, 100);
        $service->create('ListRecords', 'oai_dc', null, null, null, 100, 200);
        OaiPmhResumptionToken::query()->update(['expires_at' => now()->subDay()]);

        // Create a valid token
        $service->create('ListRecords', 'oai_dc', null, null, null, 0, 50);

        $purged = $service->purgeExpired();

        expect($purged)->toBe(2)
            ->and(OaiPmhResumptionToken::count())->toBe(1);
    });
});
