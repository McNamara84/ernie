<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IgsnIdentifier;
use App\Support\UriHelper;
use Generator;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for importing IGSNs from the DataCite REST API.
 *
 * Fetches all IGSNs registered under the configured IGSN prefix using
 * cursor-based pagination to handle the full result set (~38,500 IGSNs).
 *
 * @see https://support.datacite.org/docs/api-get-lists
 * @see https://support.datacite.org/docs/pagination
 */
class IgsnImportService
{
    private PendingRequest $client;

    private string $endpoint;

    private string $igsnPrefix;

    private string $igsnClientId;

    private const PAGE_SIZE = 100;

    private const MAX_PAGE_SIZE = 1000;

    /**
     * Initialize the IGSN import service.
     *
     * Always uses the production DataCite API.
     */
    public function __construct()
    {
        $config = Config::get('datacite.production');

        $this->endpoint = $config['endpoint'];
        $this->igsnPrefix = $config['igsn_prefix'];
        $this->igsnClientId = $config['igsn_client_id'];

        if (! str_starts_with($this->endpoint, 'https://')) {
            throw new \RuntimeException(
                'DataCite production endpoint must use HTTPS. Current: '.$this->endpoint
            );
        }

        if (empty($this->igsnPrefix)) {
            throw new \RuntimeException(
                'DataCite IGSN prefix is not configured. Please set igsn_prefix in config/datacite.php.'
            );
        }

        if (empty($this->igsnClientId)) {
            throw new \RuntimeException(
                'DataCite IGSN client ID is not configured. Please set igsn_client_id in config/datacite.php.'
            );
        }

        $username = $config['username'];
        $password = $config['password'];

        if (empty($username) || empty($password)) {
            throw new \RuntimeException(
                'DataCite production credentials are not configured. '
                .'Please set DATACITE_USERNAME and DATACITE_PASSWORD.'
            );
        }

        $this->client = Http::withBasicAuth($username, $password)
            ->withHeaders([
                'Accept' => 'application/vnd.api+json',
                'User-Agent' => 'ERNIE/1.0 (GFZ Helmholtz Centre; mailto:ernie@gfz.de)',
            ])
            ->timeout(30)
            ->retry(3, 500, function (\Throwable $exception): bool {
                if (! ($exception instanceof RequestException)) {
                    $message = strtolower($exception->getMessage());
                    $retryablePatterns = ['connection', 'timeout', 'timed out', 'reset by peer', 'could not resolve'];
                    foreach ($retryablePatterns as $pattern) {
                        if (str_contains($message, $pattern)) {
                            return true;
                        }
                    }

                    if (class_exists(ConnectException::class)
                        && $exception instanceof ConnectException) {
                        return true;
                    }

                    return false;
                }

                if ($exception->response !== null && $exception->response->status() >= 500) {
                    return true;
                }

                return false;
            });

        Log::debug('IGSN import service initialized', [
            'endpoint' => $this->endpoint,
            'igsn_prefix' => $this->igsnPrefix,
            'igsn_client_id' => $this->igsnClientId,
        ]);
    }

    /**
     * Get the total count of IGSNs at DataCite.
     */
    public function getTotalIgsnCount(): int
    {
        try {
            $response = $this->client->get("{$this->endpoint}/dois", [
                'client-id' => $this->igsnClientId,
                'prefix' => $this->igsnPrefix,
                'page[size]' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $count = $data['meta']['total'] ?? 0;

                Log::debug('IGSN count from DataCite', ['count' => $count]);

                return $count;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get IGSN count from DataCite', [
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    /**
     * Fetch all IGSNs from DataCite using cursor-based pagination.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function fetchAllIgsns(): Generator
    {
        Log::info('Starting IGSN fetch from DataCite', ['prefix' => $this->igsnPrefix]);

        $cursor = '1';
        $pageCount = 0;

        do {
            $pageCount++;

            try {
                $pageResult = $this->fetchIgsnPage($cursor, self::PAGE_SIZE);
                $data = $pageResult['data'];
                $nextCursor = $pageResult['next_cursor'];

                Log::debug('Fetched IGSN page', [
                    'page' => $pageCount,
                    'records' => count($data),
                    'has_next' => $nextCursor !== null,
                ]);

                foreach ($data as $igsnRecord) {
                    yield $igsnRecord;
                }

                $cursor = $nextCursor;

                // 200ms delay between pages to respect DataCite rate limits (~6 req/sec)
                if ($cursor !== null) {
                    usleep(200000);
                }

            } catch (\Exception $e) {
                Log::error('Failed to fetch IGSN page', [
                    'page' => $pageCount,
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);

                break;
            }

        } while ($cursor !== null);

        Log::info('Completed IGSN fetch from DataCite', [
            'pages_fetched' => $pageCount,
        ]);
    }

    /**
     * Fetch a single page of IGSNs from DataCite.
     *
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null}
     *
     * @throws RequestException
     */
    public function fetchIgsnPage(string $cursor = '1', int $pageSize = self::PAGE_SIZE): array
    {
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);

        $response = $this->client->get("{$this->endpoint}/dois", [
            'client-id' => $this->igsnClientId,
            'prefix' => $this->igsnPrefix,
            'page[cursor]' => $cursor,
            'page[size]' => $pageSize,
        ]);

        if (! $response->successful()) {
            Log::error('DataCite API request failed for IGSNs', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RequestException($response);
        }

        $json = $response->json();

        $nextCursor = null;
        if (isset($json['links']['next'])) {
            $queryParams = UriHelper::getQueryParams($json['links']['next']);
            $nextCursor = $queryParams['page']['cursor'] ?? $queryParams['page[cursor]'] ?? null;
        }

        return [
            'data' => $json['data'] ?? [],
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Fetch a single IGSN DOI record from DataCite.
     *
     * @return array<string, mixed>|null
     *
     * @throws RequestException
     * @throws \RuntimeException
     */
    public function fetchSingleIgsn(string $doi): ?array
    {
        $normalizedDoi = IgsnIdentifier::normalizeDoi($doi, $this->igsnPrefix);
        if ($normalizedDoi === null) {
            return null;
        }

        try {
            $encodedDoi = urlencode($normalizedDoi);

            $response = $this->client->get("{$this->endpoint}/dois/{$encodedDoi}");

            if ($response->successful()) {
                return $response->json()['data'] ?? null;
            }

            if ($response->status() === 404) {
                return null;
            }

            Log::warning('Failed to fetch single IGSN from DataCite', [
                'doi' => $normalizedDoi,
                'status' => $response->status(),
            ]);

            throw new RequestException($response);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Exception fetching single IGSN from DataCite', [
                'doi' => $normalizedDoi,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to fetch single IGSN from DataCite.', 0, $e);
        }
    }

    /**
     * Fetch direct DataCite children that declare the parent DOI via IsPartOf.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function fetchChildIgsnsForParent(string $parentDoi): array
    {
        $normalizedParentDoi = IgsnIdentifier::normalizeDoi($parentDoi, $this->igsnPrefix);
        if ($normalizedParentDoi === null) {
            return [];
        }

        $records = [];
        $cursor = '1';

        do {
            $response = $this->client->get("{$this->endpoint}/dois", [
                'client-id' => $this->igsnClientId,
                'prefix' => $this->igsnPrefix,
                'query' => $this->relatedIdentifierQuery($normalizedParentDoi),
                'page[cursor]' => $cursor,
                'page[size]' => self::MAX_PAGE_SIZE,
            ]);

            if (! $response->successful()) {
                Log::error('DataCite API request failed for IGSN child discovery', [
                    'parent_doi' => $normalizedParentDoi,
                    'status' => $response->status(),
                ]);

                throw new RequestException($response);
            }

            $json = $response->json();
            $data = is_array($json) ? ($json['data'] ?? []) : [];
            if (is_array($data)) {
                foreach ($data as $record) {
                    if (is_array($record) && $this->recordHasParentDoi($record, $normalizedParentDoi)) {
                        $records[] = $record;
                    }
                }
            }

            $cursor = null;
            if (is_array($json) && isset($json['links']['next'])) {
                $queryParams = UriHelper::getQueryParams($json['links']['next']);
                $cursor = $queryParams['page']['cursor'] ?? $queryParams['page[cursor]'] ?? null;
            }
        } while ($cursor !== null);

        return $records;
    }

    /**
     * Extract configured-prefix parent DOI references from a DataCite IGSN record.
     *
     * @param  array<string, mixed>  $igsnRecord
     * @return list<string>
     */
    public function extractParentDois(array $igsnRecord): array
    {
        $relatedIdentifiers = $igsnRecord['attributes']['relatedIdentifiers'] ?? [];
        if (! is_array($relatedIdentifiers)) {
            return [];
        }

        $parentDois = [];
        foreach ($relatedIdentifiers as $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $relationType = strtolower((string) ($relatedIdentifier['relationType'] ?? ''));
            if ($relationType !== 'ispartof') {
                continue;
            }

            $identifierType = strtolower((string) ($relatedIdentifier['relatedIdentifierType'] ?? ''));
            if ($identifierType !== '' && $identifierType !== 'doi') {
                continue;
            }

            $parentDoi = IgsnIdentifier::normalizeDoi(
                (string) ($relatedIdentifier['relatedIdentifier'] ?? ''),
                $this->igsnPrefix,
            );

            if ($parentDoi !== null) {
                $parentDois[] = $parentDoi;
            }
        }

        return array_values(array_unique($parentDois));
    }

    public function getIgsnPrefix(): string
    {
        return $this->igsnPrefix;
    }

    private function relatedIdentifierQuery(string $doi): string
    {
        return 'relatedIdentifiers.relatedIdentifier:"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $doi).'"';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordHasParentDoi(array $record, string $parentDoi): bool
    {
        return in_array($parentDoi, $this->extractParentDois($record), true);
    }
}
