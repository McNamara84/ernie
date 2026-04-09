<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhResumptionToken;
use Illuminate\Support\Str;

/**
 * Manages OAI-PMH resumption tokens for cursor-based pagination.
 *
 * Tokens are stored in the database and expire after a configurable TTL.
 */
class OaiPmhResumptionTokenService
{
    /**
     * Create a new resumption token.
     */
    public function create(
        string $verb,
        ?string $metadataPrefix,
        ?string $setSpec,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        int $cursor,
        int $completeListSize,
    ): OaiPmhResumptionToken {
        $ttl = (int) config('oaipmh.resumption_token_ttl', 86400);

        return OaiPmhResumptionToken::create([
            'token' => Str::random(64),
            'verb' => $verb,
            'metadata_prefix' => $metadataPrefix,
            'set_spec' => $setSpec,
            'from_date' => $from,
            'until_date' => $until,
            'cursor' => $cursor,
            'complete_list_size' => $completeListSize,
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }

    /**
     * Resolve a resumption token string to its stored data.
     *
     * Returns null if the token is invalid or expired.
     */
    public function resolve(string $token): ?OaiPmhResumptionToken
    {
        $record = OaiPmhResumptionToken::where('token', $token)->first();

        if ($record === null) {
            return null;
        }

        if ($record->expires_at->isPast()) {
            $record->delete();

            return null;
        }

        return $record;
    }

    /**
     * Delete a consumed resumption token.
     */
    public function consume(OaiPmhResumptionToken $token): void
    {
        $token->delete();
    }

    /**
     * Purge all expired resumption tokens.
     */
    public function purgeExpired(): int
    {
        return OaiPmhResumptionToken::where('expires_at', '<', now())->delete();
    }
}
