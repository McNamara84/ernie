<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use Illuminate\Console\Command;

/**
 * Purge expired OAI-PMH resumption tokens.
 */
class PurgeExpiredOaiPmhTokens extends Command
{
    protected $signature = 'oaipmh:purge-tokens';

    protected $description = 'Purge expired OAI-PMH resumption tokens';

    public function handle(OaiPmhResumptionTokenService $tokenService): int
    {
        $count = $tokenService->purgeExpired();

        $this->info("Purged {$count} expired OAI-PMH resumption token(s).");

        return self::SUCCESS;
    }
}
