<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

/** Only the facts needed for review, never the provider's full response. */
final readonly class AwardCandidate
{
    /** @param list<string> $funderIds
     * @param  list<string>  $titles
     * @param  list<string>  $programmes
     * @param  list<string>  $aliases  Explicit provider links identifying this award.
     */
    public function __construct(
        public string $provider,
        public string $recordId,
        public string $awardNumber,
        public string $uri,
        public array $funderIds,
        public array $titles,
        public array $programmes,
        public string $fetchedAt,
        public string $sourceUrl,
        public array $aliases = [],
        public ?string $sourceUpdatedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'provider' => $this->provider, 'record_id' => $this->recordId,
            'award_number' => $this->awardNumber, 'award_uri' => $this->uri,
            'funder_ids' => $this->funderIds, 'titles' => $this->titles,
            'programmes' => $this->programmes, 'aliases' => $this->aliases,
            'fetched_at' => $this->fetchedAt, 'source_url' => $this->sourceUrl,
            'source_updated_at' => $this->sourceUpdatedAt,
            'attribution' => $this->provider === 'cordis' ? 'Source: European Union, CORDIS. Normalized metadata.' : 'Source: Crossref. Normalized grant metadata.',
            'license_url' => $this->provider === 'cordis' ? 'https://cordis.europa.eu/about/legal/en' : 'https://www.crossref.org/documentation/retrieve-metadata/',
        ];
    }
}
