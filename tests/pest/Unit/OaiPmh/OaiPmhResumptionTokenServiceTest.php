<?php

declare(strict_types=1);

use App\Models\OaiPmhResumptionToken;
use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('create', function () {
    it('creates a token with correct attributes', function () {
        $service = app(OaiPmhResumptionTokenService::class);

        $token = $service->create(
            verb: 'ListRecords',
            metadataPrefix: 'oai_dc',
            setSpec: 'resourcetype:Dataset',
            from: null,
            until: null,
            cursor: 100,
            completeListSize: 500,
        );

        expect($token)->toBeInstanceOf(OaiPmhResumptionToken::class)
            ->and($token->verb)->toBe('ListRecords')
            ->and($token->metadata_prefix)->toBe('oai_dc')
            ->and($token->set_spec)->toBe('resourcetype:Dataset')
            ->and($token->cursor)->toBe(100)
            ->and($token->complete_list_size)->toBe(500)
            ->and($token->token)->toHaveLength(64)
            ->and($token->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });
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
