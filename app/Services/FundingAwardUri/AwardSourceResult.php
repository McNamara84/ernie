<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

final readonly class AwardSourceResult
{
    /** @param list<AwardCandidate> $candidates */
    public function __construct(public array $candidates = [], public ?string $error = null) {}
}
