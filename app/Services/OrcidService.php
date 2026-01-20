<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Negative cache TTL for non-existent ORCIDs (1 hour)
     */
    private const NEGATIVE_CACHE_TTL = 3600;

    /**
     * Timeout for validation requests (seconds)
     * Conservative value to handle sporadic API slowdowns
     */
    private const VALIDATION_TIMEOUT = 7;

    /**
     * Timeout for full record fetch requests (seconds)
     */
    private const FETCH_TIMEOUT = 12;

    /**
     * Maximum number of attempts for API calls (1 initial + retries)
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Initial retry delay in seconds (doubles with each retry)
     */
    private const RETRY_DELAY = 2;

    /**
     * ORCID ID validation pattern
     * Format: XXXX-XXXX-XXXX-XXXX (with check digit)
     */
    private const ORCID_PATTERN = '/^(\d{4}-\d{4}-\d{4}-\d{3}[0-9X])$/';

    /**
     * Validate ORCID ID format
     *
     * @param  string  $orcid  The ORCID ID to validate
     * @return bool True if valid format
     */
    #[\NoDiscard('Format validation result must be checked')]
    public function validateOrcidFormat(string $orcid): bool
    {
        return (bool) preg_match(self::ORCID_PATTERN, $orcid);
    }

    /**
     * Validate ORCID checksum using ISO 7064 MOD 11-2 algorithm
     *
     * The last character of an ORCID is a checksum calculated using this algorithm.
     * This allows offline validation of ORCID format correctness.
     *
     * @param  string  $orcid  The ORCID ID (format: XXXX-XXXX-XXXX-XXXX)
     * @return bool True if checksum is valid
     *
     * @see https://support.orcid.org/hc/en-us/articles/360006897674-Structure-of-the-ORCID-Identifier
     */
    #[\NoDiscard('Checksum validation result must be checked')]
    public function validateOrcidChecksum(string $orcid): bool
    {
        // Remove dashes
        $digits = str_replace('-', '', $orcid);

        if (strlen($digits) !== 16) {
            return false;
        }

        // ISO 7064 MOD 11-2 algorithm
        $total = 0;
        for ($i = 0; $i < 15; $i++) {
            if (! ctype_digit($digits[$i])) {
                return false;
            }
            $digit = (int) $digits[$i];
            $total = ($total + $digit) * 2;
        }

        $remainder = $total % 11;
        $checkDigit = (12 - $remainder) % 11;
        $expectedCheckChar = $checkDigit === 10 ? 'X' : (string) $checkDigit;

        return strtoupper($digits[15]) === $expectedCheckChar;
    }

    /**
     * Validate ORCID ID format and check if it exists
     *
     * @param  string  $orcid  The ORCID ID to validate
     * @return array{valid: bool, exists: bool|null, message: string, errorType: string|null}
     */
    #[\NoDiscard('ORCID validation result must be checked')]
    public function validateOrcid(string $orcid): array
    {
        // Check format first
        if (! $this->validateOrcidFormat($orcid)) {
            return [
                'valid' => false,
                'exists' => null,
                'message' => 'Invalid ORCID format. Expected format: XXXX-XXXX-XXXX-XXXX',
                'errorType' => 'format',
            ];
        }

        // Check checksum
        if (! $this->validateOrcidChecksum($orcid)) {
            return [
                'valid' => false,
                'exists' => null,
                'message' => 'Invalid ORCID checksum',
                'errorType' => 'checksum',
            ];
        }

        // Check negative cache for known non-existent ORCIDs
        $negativeCacheKey = 'orcid:negative:'.$orcid;
        if (Cache::has($negativeCacheKey)) {
            return [
                'valid' => true,
                'exists' => false,
                'message' => 'ORCID ID not found',
                'errorType' => 'not_found',
            ];
        }

        // Try API with retry logic for transient failures
        $retryDelay = self::RETRY_DELAY;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(self::VALIDATION_TIMEOUT)
                    ->acceptJson()
                    ->get(self::API_BASE_URL.'/'.$orcid.'/person');

                if ($response->successful()) {
                    return [
                        'valid' => true,
                        'exists' => true,
                        'message' => 'Valid ORCID ID',
                        'errorType' => null,
                    ];
                }

                if ($response->status() === 404) {
                    // Cache negative result
                    Cache::put($negativeCacheKey, true, self::NEGATIVE_CACHE_TTL);

                    return [
                        'valid' => true,
                        'exists' => false,
                        'message' => 'ORCID ID not found',
                        'errorType' => 'not_found',
                    ];
                }

                // Server error (5xx) or rate limited (429) - retry if attempts remain
                if (($response->status() >= 500 || $response->status() === 429) && $attempt < self::MAX_ATTEMPTS) {
                    Log::info('ORCID API error, retrying', [
                        'orcid' => $orcid,
                        'attempt' => $attempt,
                        'status' => $response->status(),
                    ]);
                    sleep($retryDelay);
                    $retryDelay *= 2; // Exponential backoff
                    continue;
                }

                return [
                    'valid' => true,
                    'exists' => null,
                    'message' => 'ORCID service temporarily unavailable',
                    'errorType' => 'api_error',
                ];
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('ORCID validation timeout/connection error', [
                    'orcid' => $orcid,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($retryDelay);
                    $retryDelay *= 2;
                    continue;
                }

                // All retries exhausted - return checksum-validated status
                return [
                    'valid' => true,
                    'exists' => null,
                    'message' => 'Format valid, could not verify online',
                    'errorType' => 'timeout',
                ];
            } catch (\Exception $e) {
                Log::warning('ORCID validation failed', [
                    'orcid' => $orcid,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($retryDelay);
                    $retryDelay *= 2;
                    continue;
                }

                return [
                    'valid' => true,
                    'exists' => null,
                    'message' => 'Could not verify ORCID ID',
                    'errorType' => 'unknown',
                ];
            }
        }

        // @codeCoverageIgnoreStart
        // Unreachable: all loop paths return, but PHPStan requires explicit return
        return [
            'valid' => true,
            'exists' => null,
            'message' => 'Could not verify ORCID ID',
            'errorType' => 'unknown',
        ];
        // @codeCoverageIgnoreEnd
    }

    /**
     * Fetch ORCID record data
     *
     * @param  string  $orcid  The ORCID ID
     * @return array{success: bool, data: array<string, mixed>|null, error: string|null}
     */
    public function fetchOrcidRecord(string $orcid): array
    {
        // Validate format
        if (! $this->validateOrcidFormat($orcid)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Invalid ORCID format',
            ];
        }

        // Check cache first
        $cacheKey = 'orcid_record_'.$orcid;

        if (Cache::has($cacheKey)) {
            return [
                'success' => true,
                'data' => Cache::get($cacheKey),
                'error' => null,
            ];
        }

        try {
            // Fetch full record (person + activities)
            $response = Http::timeout(self::FETCH_TIMEOUT)
                ->acceptJson()
                ->get(self::API_BASE_URL.'/'.$orcid);

            if ($response->status() === 404) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'ORCID not found',
                ];
            }

            if (! $response->successful()) {
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

            $fullRecord = $response->json();

            if ($fullRecord === null) {
                Log::error('ORCID response is not valid JSON', [
                    'orcid' => $orcid,
                    'response_body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Received invalid JSON response from ORCID API',
                ];
            }

            // Extract relevant data from person and activities
            $extractedData = $this->extractPersonData($orcid, $fullRecord);

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
     * @param  string  $query  Search query (name)
     * @param  int  $limit  Number of results (max 200)
     * @return array{success: bool, data: array<string, mixed>|null, error: string|null}
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
            // Build search query for ORCID Public API
            // Search in given-names, family-name, and other-names fields
            $queryParts = explode(' ', trim($query));

            if (count($queryParts) >= 2) {
                // If multiple words, assume first is given name, rest is family name
                $givenName = array_shift($queryParts);
                $familyName = implode(' ', $queryParts);
                $searchQuery = sprintf(
                    'given-names:%s AND family-name:%s',
                    $givenName,
                    $familyName
                );
            } else {
                // Single word - search in all name fields
                $searchQuery = sprintf(
                    '(given-names:%s OR family-name:%s OR other-names:%s)',
                    $query,
                    $query,
                    $query
                );
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->get(self::SEARCH_URL, [
                    'q' => $searchQuery,
                    'rows' => $limit,
                ]);

            if (! $response->successful()) {
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

            if ($searchResults === null) {
                Log::error('ORCID search response is not valid JSON', [
                    'query' => $query,
                    'response_body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Received invalid JSON response from ORCID search API',
                ];
            }

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
     * @param  string  $orcid  The ORCID ID
     * @param  array<string, mixed>  $fullRecord  Raw API response (full record with person + activities)
     * @return array<string, mixed> Extracted person data
     */
    private function extractPersonData(string $orcid, array $fullRecord): array
    {
        // Extract person data
        $personData = $fullRecord['person'] ?? [];
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

        // Extract affiliations from activities
        $affiliations = [];
        $activities = $fullRecord['activities-summary'] ?? [];

        // Employment affiliations (current employments only - where end-date is null)
        if (isset($activities['employments']['affiliation-group'])) {
            foreach ($activities['employments']['affiliation-group'] as $group) {
                $summaries = $group['summaries'] ?? [];
                foreach ($summaries as $summary) {
                    $employment = $summary['employment-summary'] ?? null;
                    if ($employment) {
                        // Only include current employments (no end-date)
                        $hasEndDate = ! empty($employment['end-date']);

                        if (! $hasEndDate) {
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
        }

        // Education affiliations (current education only - where end-date is null)
        if (isset($activities['educations']['affiliation-group'])) {
            foreach ($activities['educations']['affiliation-group'] as $group) {
                $summaries = $group['summaries'] ?? [];
                foreach ($summaries as $summary) {
                    $education = $summary['education-summary'] ?? null;
                    if ($education) {
                        // Only include current education (no end-date)
                        $hasEndDate = ! empty($education['end-date']);

                        if (! $hasEndDate) {
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
     * @param  array<string, mixed>  $searchResults  Raw API response
     * @return array<int, array<string, mixed>> Extracted search results
     */
    private function extractSearchResults(array $searchResults): array
    {
        $results = [];

        if (! isset($searchResults['result'])) {
            return $results;
        }

        foreach ($searchResults['result'] as $result) {
            $orcidIdentifier = $result['orcid-identifier'] ?? null;

            if (! $orcidIdentifier) {
                continue;
            }

            $orcid = $orcidIdentifier['path'] ?? null;

            if (! $orcid) {
                continue;
            }

            // ORCID Search API returns only the ORCID ID, not the full profile
            // We need to fetch the full record to get name and affiliations
            $personData = $this->fetchOrcidRecord($orcid);

            if ($personData['success'] && $personData['data']) {
                $data = $personData['data'];

                // Extract institution names from affiliations array
                $institutions = [];
                if (isset($data['affiliations']) && is_array($data['affiliations'])) {
                    foreach ($data['affiliations'] as $affiliation) {
                        if (isset($affiliation['name']) && ! empty($affiliation['name'])) {
                            $institutions[] = $affiliation['name'];
                        }
                    }
                }

                $results[] = [
                    'orcid' => $orcid,
                    'firstName' => $data['firstName'] ?? '',
                    'lastName' => $data['lastName'] ?? '',
                    'creditName' => $data['creditName'] ?? null,
                    'institutions' => $institutions,
                ];
            } else {
                // Fallback if fetching full record fails
                $results[] = [
                    'orcid' => $orcid,
                    'firstName' => '',
                    'lastName' => '',
                    'creditName' => null,
                    'institutions' => [],
                ];
            }
        }

        return $results;
    }
}
