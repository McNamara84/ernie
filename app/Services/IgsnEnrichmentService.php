<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LegacyIgsnPortalException;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\Igsn\IgsnDifMetadataExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates IGSN metadata enrichment using a fallback chain.
 *
 * Strategy: Solr → Legacy DB → public legacy portal.
 * Enrichment is non-critical: failures are logged but don't stop the import.
 * Single imports can opt into strict preloading, which makes portal failures
 * fatal before the first domain write and keeps network I/O out of the import
 * transaction.
 */
class IgsnEnrichmentService
{
    /** @var array{status: string, source: 'solr'|'legacy_db'|'portal'|null} */
    private array $lastResult = ['status' => 'not_attempted', 'source' => null];

    private bool $reportedUnavailableSources = false;

    /** @var array<string, string|null>|null */
    private ?array $strictDifByHandle = null;

    /** @var array<string, string> */
    private array $strictParentByHandle = [];

    public function __construct(
        private IgsnSolrEnrichmentService $solrService,
        private IgsnLegacyDbEnrichmentService $dbService,
        private LegacyIgsnPortalService $portalService,
        private IgsnDifXmlParser $difParser,
        private IgsnDifMetadataExtractor $difExtractor,
    ) {}

    /**
     * Preload and validate all legacy metadata required by an atomic single
     * import. A reachable portal without a DIF document is authoritative and
     * represented by null. Technical and malformed responses are exceptions.
     *
     * @param  list<string>  $handles
     */
    public function prepareStrict(array $handles): void
    {
        $this->strictDifByHandle = $this->portalService->difForHandles($handles);
        $this->strictParentByHandle = [];

        foreach ($this->strictDifByHandle as $handle => $difXml) {
            if ($difXml === null) {
                continue;
            }

            try {
                $metadata = $this->difExtractor->extract($difXml);
            } catch (\Throwable $exception) {
                throw new LegacyIgsnPortalException(
                    'The legacy IGSN portal returned DIF metadata that could not be normalized.',
                    LegacyIgsnPortalException::INVALID_PAYLOAD,
                    true,
                    $exception,
                );
            }

            if ($metadata === null) {
                throw LegacyIgsnPortalException::invalidPayload(
                    'The legacy IGSN portal returned invalid DIF XML.'
                );
            }

            $parent = $metadata['parent_igsn'] ?? null;
            if (is_string($parent) && trim($parent) !== '') {
                $this->strictParentByHandle[$handle] = strtoupper(trim($parent));
            }
        }
    }

    /** @return array<string, string> Child handle to parent handle. */
    public function preparedParentHandles(): array
    {
        return $this->strictParentByHandle;
    }

    public function clearStrictPreparation(): void
    {
        $this->strictDifByHandle = null;
        $this->strictParentByHandle = [];
    }

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

        if ($this->strictDifByHandle !== null) {
            if (! array_key_exists($igsnHandle, $this->strictDifByHandle)) {
                throw new \RuntimeException(
                    "Legacy metadata was not prepared for IGSN {$igsnHandle}."
                );
            }

            $difXml = $this->strictDifByHandle[$igsnHandle];
            if ($difXml === null) {
                $this->lastResult = ['status' => 'no_dif_found', 'source' => 'portal'];

                return false;
            }

            if (! $this->difParser->enrichFromDifXml($difXml, $resource, $igsnMetadata)) {
                throw LegacyIgsnPortalException::invalidPayload(
                    'The prepared legacy DIF metadata could not be persisted.'
                );
            }

            $this->lastResult = ['status' => 'enriched', 'source' => 'portal'];
            Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'portal']);

            return true;
        }

        $attempted = false;
        $configuration = $this->configurationStatus();

        // Try Solr first (primary source, ~92% coverage)
        if ($configuration['solr'] && $this->solrService->isAvailable()) {
            $attempted = true;
            $result = $this->solrService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                $this->lastResult = ['status' => 'enriched', 'source' => 'solr'];
                Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'solr']);

                return true;
            }
        }

        // Fallback to legacy DB (adds ~2.3% more coverage)
        if ($configuration['legacy_db'] && $this->dbService->isAvailable()) {
            $attempted = true;
            $result = $this->dbService->enrich($resource, $igsnMetadata, $igsnHandle);
            if ($result) {
                $this->lastResult = ['status' => 'enriched', 'source' => 'legacy_db'];
                Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'legacy_db']);

                return true;
            }
        }

        if ($configuration['portal']) {
            $attempted = true;

            try {
                $difXml = $this->portalService->difForHandles([$igsnHandle])[$igsnHandle] ?? null;
                if ($difXml !== null && $this->difParser->enrichFromDifXml($difXml, $resource, $igsnMetadata)) {
                    $this->lastResult = ['status' => 'enriched', 'source' => 'portal'];
                    Log::info('IGSN legacy enrichment completed', ['doi' => $doi, 'source' => 'portal']);

                    return true;
                }
            } catch (LegacyIgsnPortalException $exception) {
                Log::warning('IGSN portal enrichment failed', [
                    'doi' => $doi,
                    'failure_code' => $exception->failureCode,
                ]);
            }
        }

        $this->lastResult['status'] = $attempted ? 'no_dif_found' : 'sources_unavailable';
        $context = [
            'doi' => $doi,
            'igsn_handle' => $igsnHandle,
            'status' => $this->lastResult['status'],
            'configuration' => $configuration,
        ];
        if (! $attempted && ! $this->reportedUnavailableSources) {
            Log::warning('IGSN enrichment sources are unavailable; imports will contain DataCite metadata only', $context);
            $this->reportedUnavailableSources = true;
        } else {
            Log::debug('IGSN imported without legacy enrichment', $context);
        }

        return false;
    }

    /** @return array{solr: bool, legacy_db: bool, portal: bool} */
    public function configurationStatus(): array
    {
        $portalUrl = trim((string) config('datacite.legacy_igsn_portal.proxy_url'));

        return [
            'solr' => filled(config('datacite.solr.host'))
                && filled(config('datacite.solr.user'))
                && filled(config('datacite.solr.password')),
            'legacy_db' => (bool) config('database.connections.igsn_legacy.configured', false),
            'portal' => str_starts_with($portalUrl, 'https://'),
        ];
    }

    /** @return array{status: string, source: 'solr'|'legacy_db'|'portal'|null} */
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
