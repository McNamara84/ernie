<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for importing DOIs from the DataCite REST API.
 *
 * Fetches all DOIs registered with the configured credentials using
 * cursor-based pagination to handle unlimited result sets.
 *
 * @see https://support.datacite.org/docs/api-get-lists
 * @see https://support.datacite.org/docs/pagination
 */
class DataCiteImportService
{
    /**
     * The DataCite API client instance.
     */
    private PendingRequest $client;

    /**
     * The API endpoint URL.
     */
    private string $endpoint;

    /**
     * The DataCite client ID for filtering DOIs.
     */
    private string $clientId;

    /**
     * DOI prefixes to import.
     *
     * @var array<int, string>
     */
    private array $prefixes;

    /**
     * Default page size for API requests.
     */
    private const PAGE_SIZE = 100;

    /**
     * Maximum page size allowed by DataCite API.
     */
    private const MAX_PAGE_SIZE = 1000;

    /**
     * Initialize the DataCite import service.
     *
     * Always uses production API for import operations.
     */
    public function __construct()
    {
        // Always use production API for imports
        $config = Config::get('datacite.production');

        $this->endpoint = $config['endpoint'];
        $this->clientId = $config['client_id'];
        $this->prefixes = $config['prefixes'];

        // Validate endpoint uses HTTPS in production
        if (! str_starts_with($this->endpoint, 'https://')) {
            throw new \RuntimeException(
                'DataCite production endpoint must use HTTPS. Current: '.$this->endpoint
            );
        }

        // Validate client ID is configured
        if (empty($this->clientId)) {
            throw new \RuntimeException(
                'DataCite client_id is not configured. Please set DATACITE_CLIENT_ID.'
            );
        }

        $username = $config['username'];
        $password = $config['password'];

        // Validate credentials
        if (empty($username) || empty($password)) {
            Log::error('DataCite import credentials missing', [
                'endpoint' => $this->endpoint,
                'username_empty' => empty($username),
                'password_empty' => empty($password),
            ]);

            throw new \RuntimeException(
                'DataCite production credentials are not configured. '
                .'Please set DATACITE_USERNAME and DATACITE_PASSWORD.'
            );
        }

        // Initialize HTTP client with authentication
        $this->client = Http::withBasicAuth($username, $password)
            ->withHeaders([
                'Accept' => 'application/vnd.api+json',
                'User-Agent' => 'ERNIE/1.0 (GFZ Helmholtz Centre; mailto:ernie@gfz.de)',
            ])
            ->timeout(30)
            ->retry(3, 500, function (\Exception $exception): bool {
                // Only retry on connection-related exceptions (timeouts, network errors)
                // Don't retry on non-recoverable errors like SSL failures or malformed responses
                if (! ($exception instanceof RequestException)) {
                    // Check if it's a connection-related exception that might succeed on retry
                    $message = strtolower($exception->getMessage());
                    $retryablePatterns = ['connection', 'timeout', 'timed out', 'reset by peer'];
                    foreach ($retryablePatterns as $pattern) {
                        if (str_contains($message, $pattern)) {
                            return true;
                        }
                    }
                    // Don't retry unknown exceptions
                    return false;
                }

                // Retry on 5xx server errors (temporary server issues)
                if ($exception->response !== null && $exception->response->status() >= 500) {
                    return true;
                }

                // Don't retry on 4xx client errors (permanent failures)
                return false;
            });

        Log::debug('DataCite import service initialized', [
            'endpoint' => $this->endpoint,
            'prefixes' => $this->prefixes,
        ]);
    }

    /**
     * Get the total count of DOIs across all configured prefixes.
     *
     * @return int Total number of DOIs
     */
    public function getTotalDoiCount(): int
    {
        $total = 0;

        foreach ($this->prefixes as $prefix) {
            try {
                $response = $this->client->get("{$this->endpoint}/dois", [
                    'client-id' => $this->clientId,
                    'prefix' => $prefix,
                    // Use page[size]=1 instead of 0 to ensure a valid API response
                    // while still minimizing data transfer - we only need the meta.total count
                    'page[size]' => 1,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $count = $data['meta']['total'] ?? 0;
                    $total += $count;

                    Log::debug('DOI count for prefix', [
                        'prefix' => $prefix,
                        'count' => $count,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get DOI count for prefix', [
                    'prefix' => $prefix,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }

    /**
     * Fetch all DOIs from DataCite using cursor-based pagination.
     *
     * Uses a generator to efficiently handle large result sets without
     * loading everything into memory at once.
     *
     * @return Generator<int, array<string, mixed>> Yields individual DOI records
     */
    public function fetchAllDois(): Generator
    {
        foreach ($this->prefixes as $prefix) {
            Log::info('Starting DOI fetch for prefix', ['prefix' => $prefix]);

            yield from $this->fetchDoisForPrefix($prefix);
        }
    }

    /**
     * Fetch all DOIs for a specific prefix.
     *
     * @param  string  $prefix  The DOI prefix
     * @return Generator<int, array<string, mixed>> Yields individual DOI records
     */
    private function fetchDoisForPrefix(string $prefix): Generator
    {
        $cursor = '1'; // Initial cursor for first page
        $pageCount = 0;

        do {
            $pageCount++;

            try {
                $pageResult = $this->fetchDoiPage($prefix, $cursor, self::PAGE_SIZE);
                $data = $pageResult['data'];
                $nextCursor = $pageResult['next_cursor'];

                Log::debug('Fetched DOI page', [
                    'prefix' => $prefix,
                    'page' => $pageCount,
                    'records' => count($data),
                    'has_next' => $nextCursor !== null,
                ]);

                foreach ($data as $doiRecord) {
                    yield $doiRecord;
                }

                $cursor = $nextCursor;

                // Delay between pages to respect DataCite rate limits (~6 req/sec)
                // Calculation: 1000ms / 200ms = 5 requests/second, safely under the 6 req/sec limit
                // See https://support.datacite.org/docs/is-there-a-rate-limit-for-making-requests-against-the-datacite-apis
                if ($cursor !== null) {
                    usleep(200000); // 200ms delay = max 5 req/sec
                }

            } catch (\Exception $e) {
                Log::error('Failed to fetch DOI page', [
                    'prefix' => $prefix,
                    'page' => $pageCount,
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);

                // Stop processing this prefix on error
                break;
            }

        } while ($cursor !== null);

        Log::info('Completed DOI fetch for prefix', [
            'prefix' => $prefix,
            'pages_fetched' => $pageCount,
        ]);
    }

    /**
     * Fetch a single page of DOIs from DataCite.
     *
     * @param  string  $prefix  The DOI prefix
     * @param  string  $cursor  The pagination cursor
     * @param  int  $pageSize  Number of records per page
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null}
     *
     * @throws RequestException If the API request fails
     */
    public function fetchDoiPage(string $prefix, string $cursor = '1', int $pageSize = self::PAGE_SIZE): array
    {
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);

        $response = $this->client->get("{$this->endpoint}/dois", [
            'client-id' => $this->clientId,
            'prefix' => $prefix,
            'page[cursor]' => $cursor,
            'page[size]' => $pageSize,
        ]);

        if (! $response->successful()) {
            Log::error('DataCite API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'prefix' => $prefix,
            ]);

            throw new RequestException($response);
        }

        $json = $response->json();

        // Extract next cursor from links
        $nextCursor = null;
        if (isset($json['links']['next'])) {
            $nextUrl = $json['links']['next'];
            // Extract cursor from URL query parameter
            $parsedUrl = parse_url($nextUrl);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                $nextCursor = $queryParams['page']['cursor'] ?? $queryParams['page[cursor]'] ?? null;
            }
        }

        return [
            'data' => $json['data'] ?? [],
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Fetch a single DOI record by its identifier.
     *
     * @param  string  $doi  The DOI to fetch
     * @return array<string, mixed>|null The DOI record or null if not found
     */
    public function fetchSingleDoi(string $doi): ?array
    {
        try {
            // Encode the DOI for URL
            $encodedDoi = urlencode($doi);

            $response = $this->client->get("{$this->endpoint}/dois/{$encodedDoi}");

            if ($response->successful()) {
                return $response->json()['data'] ?? null;
            }

            if ($response->status() === 404) {
                return null;
            }

            Log::warning('Failed to fetch single DOI', [
                'doi' => $doi,
                'status' => $response->status(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Exception fetching single DOI', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the configured prefixes.
     *
     * @return array<int, string>
     */
    public function getPrefixes(): array
    {
        return $this->prefixes;
    }
}
