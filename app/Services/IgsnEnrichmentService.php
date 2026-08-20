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
    /** @var array{status: string, source: 'solr'|'legacy_db'|null} */
    private array $lastResult = ['status' => 'not_attempted', 'source' => null];

    private bool $reportedUnavailableSources = false;

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
        $this->lastResult = ['status' => 'datacite_only', 'source' => null];
        $doi = $resource->doi;
        if ($doi === null) {
            $this->lastResult['status'] = 'invalid_identifier';

            return false;
        }

        // Extract IGSN handle from DOI: "10.60510/GFBNO7002EC8H101" → "GFBNO7002EC8H101"
        $igsnHandle = $this->extractHandle($doi);
        if ($igsnHandle === null) {
            $this->lastResult['status'] = 'invalid_identifier';

            return false;
        }

        $attempted = false;

        // Try Solr first (primary source, ~92% coverage)
        if ($this->solrService->isAvailable()) {
            $attempted = true;
            $result = $this->solrService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                $this->lastResult = ['status' => 'enriched', 'source' => 'solr'];
                Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'solr']);

                return true;
            }
        }

        // Fallback to legacy DB (adds ~2.3% more coverage)
        if ($this->dbService->isAvailable()) {
            $attempted = true;
            $result = $this->dbService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                $this->lastResult = ['status' => 'enriched', 'source' => 'legacy_db'];
                Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'legacy_db']);

                return true;
            }
        }

        $this->lastResult['status'] = $attempted ? 'no_dif_found' : 'sources_unavailable';
        $context = [
            'doi' => $doi,
            'igsn_handle' => $igsnHandle,
            'status' => $this->lastResult['status'],
            'configuration' => $this->configurationStatus(),
        ];
        if (! $attempted && ! $this->reportedUnavailableSources) {
            Log::warning('IGSN enrichment sources are unavailable; imports will contain DataCite metadata only', $context);
            $this->reportedUnavailableSources = true;
        } else {
            Log::debug('IGSN imported without legacy enrichment', $context);
        }

        return false;
    }

    /** @return array{solr: bool, legacy_db: bool} */
    public function configurationStatus(): array
    {
        return [
            'solr' => filled(config('datacite.solr.host'))
                && filled(config('datacite.solr.user'))
                && filled(config('datacite.solr.password')),
            'legacy_db' => (bool) config('database.connections.igsn_legacy.configured', false),
        ];
    }

    /** @return array{status: string, source: 'solr'|'legacy_db'|null} */
    public function lastResult(): array
    {
        return $this->lastResult;
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
