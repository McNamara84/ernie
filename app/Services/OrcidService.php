<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ORCID Service
 * 
 * Handles communication with the ORCID Public API v3.0
 * 
 * @see https://info.orcid.org/documentation/api-tutorials/api-tutorial-read-data-on-a-record/
 * @see https://pub.orcid.org/v3.0/
 */
class OrcidService
{
    /**
     * ORCID Public API Base URL
     */
    private const API_BASE_URL = 'https://pub.orcid.org/v3.0';

    /**
     * ORCID Public API Search URL
     */
    private const SEARCH_URL = 'https://pub.orcid.org/v3.0/search';

    /**
     * Cache TTL for ORCID records (24 hours)
     */
    private const CACHE_TTL = 86400;

    /**
     * ORCID ID validation pattern
     * Format: XXXX-XXXX-XXXX-XXXX (with check digit)
     */
    private const ORCID_PATTERN = '/^(\d{4}-\d{4}-\d{4}-\d{3}[0-9X])$/';

    /**
     * Validate ORCID ID format
     * 
     * @param string $orcid The ORCID ID to validate
     * @return bool True if valid format
     */
    public function validateOrcidFormat(string $orcid): bool
    {
        return (bool) preg_match(self::ORCID_PATTERN, $orcid);
    }

    /**
     * Validate ORCID ID format and check if it exists
     * 
     * @param string $orcid The ORCID ID to validate
     * @return array{valid: bool, exists: bool|null, message: string}
     */
    public function validateOrcid(string $orcid): array
    {
        // Check format first
        if (!$this->validateOrcidFormat($orcid)) {
            return [
                'valid' => false,
                'exists' => null,
                'message' => 'Invalid ORCID format. Expected format: XXXX-XXXX-XXXX-XXXX',
            ];
        }

        // Check if ORCID exists via API
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get(self::API_BASE_URL . '/' . $orcid . '/person');

            if ($response->successful()) {
                return [
                    'valid' => true,
                    'exists' => true,
                    'message' => 'Valid ORCID ID',
                ];
            }

            if ($response->status() === 404) {
                return [
                    'valid' => true,
                    'exists' => false,
                    'message' => 'ORCID ID not found',
                ];
            }

            return [
                'valid' => true,
                'exists' => null,
                'message' => 'Could not verify ORCID ID',
            ];
        } catch (\Exception $e) {
            Log::warning('ORCID validation failed', [
                'orcid' => $orcid,
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => true,
                'exists' => null,
                'message' => 'Could not verify ORCID ID',
            ];
        }
    }

    /**
     * Fetch ORCID record data
     * 
     * @param string $orcid The ORCID ID
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchOrcidRecord(string $orcid): array
    {
        // Validate format
        if (!$this->validateOrcidFormat($orcid)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Invalid ORCID format',
            ];
        }

        // Check cache first
        $cacheKey = 'orcid_record_' . $orcid;
        
        if (Cache::has($cacheKey)) {
            return [
                'success' => true,
                'data' => Cache::get($cacheKey),
                'error' => null,
            ];
        }

        try {
            // Fetch person data
            $response = Http::timeout(10)
                ->acceptJson()
                ->get(self::API_BASE_URL . '/' . $orcid . '/person');

            if ($response->status() === 404) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'ORCID not found',
                ];
            }

            if (!$response->successful()) {
                Log::error('ORCID API request failed', [
                    'orcid' => $orcid,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Failed to fetch ORCID data',
                ];
            }

            $personData = $response->json();
            
            // Extract relevant data
            $extractedData = $this->extractPersonData($orcid, $personData);

            // Cache the result
            Cache::put($cacheKey, $extractedData, self::CACHE_TTL);

            return [
                'success' => true,
                'data' => $extractedData,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('ORCID fetch failed', [
                'orcid' => $orcid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => 'An error occurred while fetching ORCID data',
            ];
        }
    }

    /**
     * Search for ORCID records by name
     * 
     * @param string $query Search query (name)
     * @param int $limit Number of results (max 200)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function searchOrcid(string $query, int $limit = 10): array
    {
        if (empty(trim($query))) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Search query is required',
            ];
        }

        // Limit results (ORCID API max is 200)
        $limit = min($limit, 200);

        try {
            // Build search query
            $searchQuery = 'given-and-family-names:"' . addslashes($query) . '"';

            $response = Http::timeout(10)
                ->acceptJson()
                ->get(self::SEARCH_URL, [
                    'q' => $searchQuery,
                    'rows' => $limit,
                ]);

            if (!$response->successful()) {
                Log::error('ORCID search failed', [
                    'query' => $query,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Search request failed',
                ];
            }

            $searchResults = $response->json();
            
            // Extract results
            $results = $this->extractSearchResults($searchResults);

            return [
                'success' => true,
                'data' => [
                    'results' => $results,
                    'total' => $searchResults['num-found'] ?? 0,
                ],
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('ORCID search exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => 'An error occurred during search',
            ];
        }
    }

    /**
     * Extract person data from ORCID API response
     * 
     * @param string $orcid The ORCID ID
     * @param array $personData Raw API response
     * @return array Extracted person data
     */
    private function extractPersonData(string $orcid, array $personData): array
    {
        $name = $personData['name'] ?? [];
        $givenNames = $name['given-names']['value'] ?? '';
        $familyName = $name['family-name']['value'] ?? '';
        $creditName = $name['credit-name']['value'] ?? null;

        // Extract emails
        $emails = [];
        if (isset($personData['emails']['email'])) {
            foreach ($personData['emails']['email'] as $email) {
                if (isset($email['email'])) {
                    $emails[] = $email['email'];
                }
            }
        }

        // Extract affiliations (employments + educations)
        $affiliations = [];
        
        // Employment affiliations
        if (isset($personData['employments']['affiliation-group'])) {
            foreach ($personData['employments']['affiliation-group'] as $group) {
                $summaries = $group['summaries'] ?? [];
                foreach ($summaries as $summary) {
                    $employment = $summary['employment-summary'] ?? null;
                    if ($employment) {
                        $affiliations[] = [
                            'type' => 'employment',
                            'name' => $employment['organization']['name'] ?? null,
                            'role' => $employment['role-title'] ?? null,
                            'department' => $employment['department-name'] ?? null,
                        ];
                    }
                }
            }
        }

        // Education affiliations
        if (isset($personData['educations']['affiliation-group'])) {
            foreach ($personData['educations']['affiliation-group'] as $group) {
                $summaries = $group['summaries'] ?? [];
                foreach ($summaries as $summary) {
                    $education = $summary['education-summary'] ?? null;
                    if ($education) {
                        $affiliations[] = [
                            'type' => 'education',
                            'name' => $education['organization']['name'] ?? null,
                            'role' => $education['role-title'] ?? null,
                            'department' => $education['department-name'] ?? null,
                        ];
                    }
                }
            }
        }

        return [
            'orcid' => $orcid,
            'firstName' => $givenNames,
            'lastName' => $familyName,
            'creditName' => $creditName,
            'emails' => $emails,
            'affiliations' => $affiliations,
            'verifiedAt' => now()->toISOString(),
        ];
    }

    /**
     * Extract search results from ORCID API response
     * 
     * @param array $searchResults Raw API response
     * @return array Extracted search results
     */
    private function extractSearchResults(array $searchResults): array
    {
        $results = [];

        if (!isset($searchResults['result'])) {
            return $results;
        }

        foreach ($searchResults['result'] as $result) {
            $orcidIdentifier = $result['orcid-identifier'] ?? null;
            
            if (!$orcidIdentifier) {
                continue;
            }

            $orcid = $orcidIdentifier['path'] ?? null;
            
            if (!$orcid) {
                continue;
            }

            $results[] = [
                'orcid' => $orcid,
                'firstName' => $result['given-names'] ?? '',
                'lastName' => $result['family-names'] ?? '',
                'creditName' => $result['credit-name'] ?? null,
                'institutions' => $result['institution-name'] ?? [],
            ];
        }

        return $results;
    }
}
