<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MslLaboratoryService
{
    private const LABORATORIES_JSON_URL = 'https://raw.githubusercontent.com/UtrechtUniversity/msl_vocabularies/main/vocabularies/labs/laboratories.json';
    
    private const CACHE_KEY = 'msl_laboratories';
    
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * @var array<string, array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}>|null
     */
    private ?array $laboratoriesById = null;

    /**
     * Load laboratories from GitHub, with caching
     *
     * @return array<string, array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}>
     */
    private function loadLaboratories(): array
    {
        if ($this->laboratoriesById !== null) {
            return $this->laboratoriesById;
        }

        // Try to get from cache first
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            $this->laboratoriesById = $cached;
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get(self::LABORATORIES_JSON_URL);

            if (! $response->successful()) {
                Log::warning('Failed to fetch MSL laboratories', [
                    'status' => $response->status(),
                    'url' => self::LABORATORIES_JSON_URL,
                ]);
                $this->laboratoriesById = [];
                return [];
            }

            $laboratories = $response->json();

            if (! is_array($laboratories)) {
                Log::error('MSL laboratories JSON is not an array');
                $this->laboratoriesById = [];
                return [];
            }

            // Index by identifier for fast lookup
            $indexed = [];
            foreach ($laboratories as $lab) {
                if (! is_array($lab) || ! isset($lab['identifier'])) {
                    continue;
                }

                $indexed[$lab['identifier']] = [
                    'identifier' => $lab['identifier'],
                    'name' => $lab['name'] ?? '',
                    'affiliation_name' => $lab['affiliation_name'] ?? '',
                    'affiliation_ror' => $lab['affiliation_ror'] ?? '',
                ];
            }

            // Cache for 24 hours
            Cache::put(self::CACHE_KEY, $indexed, self::CACHE_TTL);

            $this->laboratoriesById = $indexed;
            return $indexed;
        } catch (\Exception $e) {
            Log::error('Exception while fetching MSL laboratories', [
                'error' => $e->getMessage(),
                'url' => self::LABORATORIES_JSON_URL,
            ]);
            $this->laboratoriesById = [];
            return [];
        }
    }

    /**
     * Find laboratory by identifier (labid)
     *
     * @param string $labId The laboratory identifier
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}|null
     */
    public function findByLabId(string $labId): ?array
    {
        $laboratories = $this->loadLaboratories();

        return $laboratories[$labId] ?? null;
    }

    /**
     * Validate if a labid exists
     *
     * @param string $labId The laboratory identifier
     * @return bool
     */
    public function isValidLabId(string $labId): bool
    {
        $laboratories = $this->loadLaboratories();

        return isset($laboratories[$labId]);
    }

    /**
     * Enrich laboratory data with information from the vocabulary
     * 
     * @param string $labId The laboratory identifier
     * @param string|null $name Laboratory name from XML (fallback)
     * @param string|null $affiliationName Affiliation name from XML (fallback)
     * @param string|null $affiliationRor ROR URL from XML (fallback)
     * @return array{identifier: string, name: string, affiliation_name: string, affiliation_ror: string}
     */
    public function enrichLaboratoryData(
        string $labId,
        ?string $name = null,
        ?string $affiliationName = null,
        ?string $affiliationRor = null
    ): array {
        $fromVocabulary = $this->findByLabId($labId);

        if ($fromVocabulary === null) {
            // Lab ID not found in vocabulary, use provided data
            Log::warning('MSL Laboratory ID not found in vocabulary', [
                'labId' => $labId,
                'name' => $name,
            ]);

            return [
                'identifier' => $labId,
                'name' => $name ?? '',
                'affiliation_name' => $affiliationName ?? '',
                'affiliation_ror' => $affiliationRor ?? '',
            ];
        }

        // Use vocabulary data, with XML data as fallback
        return [
            'identifier' => $labId,
            'name' => $fromVocabulary['name'] ?: ($name ?? ''),
            'affiliation_name' => $fromVocabulary['affiliation_name'] ?: ($affiliationName ?? ''),
            'affiliation_ror' => $fromVocabulary['affiliation_ror'] ?: ($affiliationRor ?? ''),
        ];
    }

    /**
     * Clear the cached laboratories data
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->laboratoriesById = null;
    }
}
