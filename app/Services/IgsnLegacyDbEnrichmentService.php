<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fallback enrichment service that queries the legacy igsn-metadata database.
 *
 * Used when Solr enrichment fails or has no data for an IGSN (primarily
 * for HLL and GFZ datacentre IGSNs which lack DIF XML in Solr).
 *
 * The legacy DB stores DIF XML in the `metadata` table's `dif` column,
 * linked to `dataset` via FK. Handle mapping: DB uses prefix 10273/,
 * DataCite uses 10.60510/. The IGSN suffix is identical.
 */
class IgsnLegacyDbEnrichmentService
{
    private bool $available = true;

    private int $consecutiveFailures = 0;

    private const MAX_CONSECUTIVE_FAILURES = 5;

    public function __construct(
        private IgsnDifXmlParser $difParser,
    ) {}

    /**
     * Enrich a resource with IGSN metadata from the legacy database.
     *
     * @param  Resource  $resource  The resource to enrich
     * @param  IgsnMetadata  $igsnMetadata  The IGSN metadata record to populate
     * @param  string  $igsnHandle  The IGSN handle suffix (e.g. GFBNO7002EC8H101)
     * @return bool True if enrichment was successful
     */
    public function enrich(Resource $resource, IgsnMetadata $igsnMetadata, string $igsnHandle): bool
    {
        if (! $this->available) {
            return false;
        }

        try {
            // Query the legacy DB for DIF XML using the old handle format
            // dataset.doi uses 10273/ prefix, match by IGSN suffix (case-insensitive)
            $row = DB::connection('igsn_legacy')
                ->table('metadata')
                ->join('dataset', 'metadata.dataset', '=', 'dataset.id')
                ->where(DB::raw('UPPER(SUBSTRING_INDEX(dataset.doi, \'/\', -1))'), strtoupper($igsnHandle))
                ->whereNotNull('metadata.dif')
                ->orderByDesc('metadata.id')  // Latest version
                ->select('metadata.dif')
                ->first();

            if ($row === null || empty($row->dif)) {
                return false;
            }

            // The dif column is a BLOB — may be raw XML or gzipped
            $difXml = $row->dif;

            // Try to detect if it's gzipped
            if (str_starts_with($difXml, "\x1f\x8b")) {
                $difXml = gzdecode($difXml);
                if ($difXml === false) {
                    return false;
                }
            }

            $result = $this->difParser->enrichFromDifXml($difXml, $resource, $igsnMetadata);
            if ($result) {
                $this->consecutiveFailures = 0;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($e->getMessage());

            return false;
        }
    }

    /**
     * Check if the DB enrichment service is still available.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    private function recordFailure(string $message): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            Log::warning('Legacy DB enrichment disabled after consecutive failures', [
                'failures' => $this->consecutiveFailures,
                'last_error' => $message,
            ]);
            $this->available = false;
        } else {
            Log::debug('Legacy DB enrichment failed', ['error' => $message]);
        }
    }
}
