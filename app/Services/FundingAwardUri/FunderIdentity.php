<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

final readonly class FunderIdentity
{
    /** @param list<string> $ids
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(public string $crossrefId, public array $ids, public array $provenance = []) {}

    /** @return list<string> */
    public function cordisProgrammes(): array
    {
        $funders = config('funding_award_uri.cordis_funders', []);

        return is_array($funders) ? ($funders[$this->crossrefId]['programmes'] ?? []) : [];
    }
}
