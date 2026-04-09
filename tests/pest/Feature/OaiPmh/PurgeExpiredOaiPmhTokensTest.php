<?php

declare(strict_types=1);

use App\Models\OaiPmhResumptionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('oaipmh:purge-tokens command purges expired tokens', function () {
    // Create expired tokens
    OaiPmhResumptionToken::create([
        'token' => 'expired-token-1',
        'verb' => 'ListRecords',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 0,
        'complete_list_size' => 100,
        'expires_at' => now()->subDay(),
    ]);

    OaiPmhResumptionToken::create([
        'token' => 'expired-token-2',
        'verb' => 'ListIdentifiers',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 50,
        'complete_list_size' => 200,
        'expires_at' => now()->subHour(),
    ]);

    // Create a valid token that should NOT be purged
    OaiPmhResumptionToken::create([
        'token' => 'valid-token',
        'verb' => 'ListRecords',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 0,
        'complete_list_size' => 50,
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan('oaipmh:purge-tokens')
        ->expectsOutputToContain('Purged 2 expired')
        ->assertExitCode(0);

    expect(OaiPmhResumptionToken::count())->toBe(1)
        ->and(OaiPmhResumptionToken::where('token', 'valid-token')->exists())->toBeTrue();
});

test('oaipmh:purge-tokens command handles no expired tokens', function () {
    $this->artisan('oaipmh:purge-tokens')
        ->expectsOutputToContain('Purged 0 expired')
        ->assertExitCode(0);
});
