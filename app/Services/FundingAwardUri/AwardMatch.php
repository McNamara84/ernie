<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

final readonly class AwardMatch
{
    /** @param list<AwardCandidate> $evidence */
    public function __construct(public ?AwardCandidate $candidate = null, public array $evidence = [], public string $reason = 'no_match') {}
}
