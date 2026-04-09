<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates IGSN metadata enrichment using a fallback chain.
 *
 * Strategy: Solr (primary, 92% coverage) → Legacy DB (fallback, adds ~2.3%).
 * Enrichment is non-critical: failures are logged but don't stop the import.
 */
class IgsnEnrichmentService
{
    public function __construct(
        private IgsnSolrEnrichmentService $solrService,
        private IgsnLegacyDbEnrichmentService $dbService,
    ) {}

    /**
     * Enrich a resource with IGSN-specific metadata from legacy sources.
     *
     * Extracts the IGSN handle from the DOI and tries enrichment sources
     * in priority order until one succeeds.
     *
     * @param  Resource  $resource  The resource to enrich (must have a DOI)
     * @param  IgsnMetadata  $igsnMetadata  The IGSN metadata record to populate
     * @return bool True if enrichment was successful from any source
     */
    public function enrich(Resource $resource, IgsnMetadata $igsnMetadata): bool
    {
        $doi = $resource->doi;
        if ($doi === null) {
            return false;
        }

        // Extract IGSN handle from DOI: "10.60510/GFBNO7002EC8H101" → "GFBNO7002EC8H101"
        $igsnHandle = $this->extractHandle($doi);
        if ($igsnHandle === null) {
            return false;
        }

        // Try Solr first (primary source, ~92% coverage)
        if ($this->solrService->isAvailable()) {
            $result = $this->solrService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                return true;
            }
        }

        // Fallback to legacy DB (adds ~2.3% more coverage)
        if ($this->dbService->isAvailable()) {
            $result = $this->dbService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                return true;
            }
        }

        Log::debug('No enrichment source had data for IGSN', [
            'doi' => $doi,
            'igsn_handle' => $igsnHandle,
        ]);

        return false;
    }

    /**
     * Extract the IGSN handle suffix from a DataCite DOI.
     *
     * @param  string  $doi  Full DOI (e.g. "10.60510/gfbno7002ec8h101")
     * @return string|null The handle suffix in uppercase, or null if invalid
     */
    private function extractHandle(string $doi): ?string
    {
        $parts = explode('/', $doi, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        return strtoupper($parts[1]);
    }
}
