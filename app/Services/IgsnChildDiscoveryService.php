<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IgsnIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IgsnChildDiscoveryService
{
    /**
     * @return list<string> Uppercase direct child handles.
     */
    public function discoverDirectChildHandles(string $parentHandle): array
    {
        $parentHandle = $this->normalizeHandle($parentHandle);
        if ($parentHandle === null) {
            return [];
        }

        $handles = [
            ...$this->discoverFromSolr($parentHandle),
            ...$this->discoverFromLegacyDb($parentHandle),
        ];

        return array_values(array_unique(array_filter(
            $handles,
            fn (string $handle): bool => $handle !== $parentHandle && IgsnIdentifier::isValidHandle($handle),
        )));
    }

    /**
     * @return list<string>
     */
    private function discoverFromSolr(string $parentHandle): array
    {
        $solrHost = config('datacite.solr.host');
        $solrPort = config('datacite.solr.port', '443');
        $solrUser = config('datacite.solr.user');
        $solrPassword = config('datacite.solr.password');

        if (empty($solrHost) || empty($solrUser) || empty($solrPassword)) {
            return [];
        }

        try {
            $url = "https://{$solrHost}:{$solrPort}/solr/igsnaa/select";

            $response = Http::withBasicAuth((string) $solrUser, (string) $solrPassword)
                ->timeout(15)
                ->get($url, [
                    'q' => sprintf('parent_igsn:%1$s OR dif:%1$s', $parentHandle),
                    'wt' => 'json',
                    'rows' => 1000,
                    'fl' => 'igsn,dif,has_dif',
                ]);

            if (! $response->successful()) {
                Log::debug('Solr child discovery failed', [
                    'parent_igsn' => $parentHandle,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $docs = $response->json('response.docs', []);
            if (! is_array($docs)) {
                return [];
            }

            $handles = [];
            foreach ($docs as $doc) {
                if (! is_array($doc)) {
                    continue;
                }

                $childHandle = $this->normalizeHandle($doc['igsn'] ?? $doc['IGSN'] ?? null);
                if ($childHandle === null) {
                    continue;
                }

                $difXml = $this->decodeDifValue($doc['dif'] ?? null);
                if ($difXml !== null && $this->extractParentHandleFromDif($difXml) !== $parentHandle) {
                    continue;
                }

                $handles[] = $childHandle;
            }

            return $handles;
        } catch (\Throwable $e) {
            Log::debug('Solr child discovery unavailable', [
                'parent_igsn' => $parentHandle,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function discoverFromLegacyDb(string $parentHandle): array
    {
        try {
            $rows = DB::connection('igsn_legacy')
                ->table('metadata')
                ->join('dataset', 'metadata.dataset', '=', 'dataset.id')
                ->whereNotNull('metadata.dif')
                ->where('metadata.dif', 'like', '%'.$parentHandle.'%')
                ->orderByDesc('metadata.id')
                ->limit(1000)
                ->select('dataset.doi', 'metadata.dif')
                ->get();
        } catch (\Throwable $e) {
            Log::debug('Legacy DB child discovery unavailable', [
                'parent_igsn' => $parentHandle,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $handles = [];
        foreach ($rows as $row) {
            $difXml = $this->decodeDifValue($row->dif ?? null);
            if ($difXml !== null && $this->extractParentHandleFromDif($difXml) !== $parentHandle) {
                continue;
            }

            $childHandle = $this->normalizeHandleFromLegacyDoi((string) ($row->doi ?? ''));
            if ($childHandle !== null) {
                $handles[] = $childHandle;
            }
        }

        return $handles;
    }

    private function normalizeHandle(mixed $handle): ?string
    {
        if (! is_string($handle) && ! is_numeric($handle)) {
            return null;
        }

        $value = strtoupper(trim((string) $handle));

        return IgsnIdentifier::isValidHandle($value) ? $value : null;
    }

    private function normalizeHandleFromLegacyDoi(string $doi): ?string
    {
        $doi = trim($doi);
        if ($doi === '' || ! str_contains($doi, '/')) {
            return null;
        }

        return $this->normalizeHandle(substr($doi, (int) strrpos($doi, '/') + 1));
    }

    private function decodeDifValue(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, "\x1f\x8b")) {
            $decoded = gzdecode($value);

            return $decoded !== false ? $decoded : null;
        }

        $base64Decoded = base64_decode($value, true);
        if ($base64Decoded !== false && str_contains($base64Decoded, '<')) {
            return $base64Decoded;
        }

        return $value;
    }

    private function extractParentHandleFromDif(string $difXml): ?string
    {
        $xml = @simplexml_load_string($difXml);
        if ($xml === false) {
            return null;
        }

        $matches = $xml->xpath('//*[local-name()="parent_igsn"]');
        if (! is_array($matches) || $matches === []) {
            return null;
        }

        return $this->normalizeHandle((string) $matches[0]);
    }
}
