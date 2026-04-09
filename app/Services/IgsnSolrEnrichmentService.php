<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enriches IGSN resources with metadata from the legacy Solr igsnaa core.
 *
 * The Solr igsnaa core contains DIF XML with rich IGSN-specific metadata
 * for ~35,429 IGSNs. This is the primary enrichment source.
 *
 * Handle mapping: DataCite uses DOI prefix 10.60510/, Solr uses old prefix 10273/.
 * The IGSN suffix (handle) is identical and case-insensitive.
 */
class IgsnSolrEnrichmentService
{
    private bool $available = true;

    private int $consecutiveFailures = 0;

    private const MAX_CONSECUTIVE_FAILURES = 10;

    public function __construct(
        private IgsnDifXmlParser $difParser,
    ) {}

    /**
     * Enrich a resource with IGSN metadata from Solr.
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

        $solrHost = config('datacite.solr.host');
        $solrPort = config('datacite.solr.port', '443');
        $solrUser = config('datacite.solr.user');
        $solrPassword = config('datacite.solr.password');

        if (empty($solrHost) || empty($solrUser) || empty($solrPassword)) {
            Log::debug('Solr credentials not configured, skipping enrichment');
            $this->available = false;

            return false;
        }

        try {
            $url = "https://{$solrHost}:{$solrPort}/solr/igsnaa/select";

            $response = Http::withBasicAuth((string) $solrUser, (string) $solrPassword)
                ->timeout(10)
                ->get($url, [
                    'q' => "igsn:{$igsnHandle}",
                    'wt' => 'json',
                    'rows' => 1,
                    'fl' => 'dif,has_dif',
                ]);

            if (! $response->successful()) {
                $this->recordFailure('Solr HTTP error: ' . $response->status());

                return false;
            }

            $data = $response->json();
            $docs = $data['response']['docs'] ?? [];

            if (count($docs) === 0) {
                // No match in Solr — not a failure, just no data
                return false;
            }

            $doc = $docs[0];
            $hasDif = $doc['has_dif'] ?? false;

            if (! $hasDif || empty($doc['dif'])) {
                return false;
            }

            // DIF field is base64-encoded
            $difXml = base64_decode($doc['dif'], true);
            if ($difXml === false || $difXml === '') {
                Log::warning('Failed to base64-decode Solr DIF XML', [
                    'resource_id' => $resource->id,
                    'igsn' => $igsnHandle,
                ]);

                return false;
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
     * Check if the Solr enrichment service is still available.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    private function recordFailure(string $message): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            Log::warning('Solr enrichment disabled after consecutive failures', [
                'failures' => $this->consecutiveFailures,
                'last_error' => $message,
            ]);
            $this->available = false;
        } else {
            Log::debug('Solr enrichment failed', ['error' => $message]);
        }
    }
}
