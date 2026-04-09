<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UriHelper;
use Generator;
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
                'DataCite production endpoint must use HTTPS. Current: ' . $this->endpoint
            );
        }

        if (empty($this->igsnPrefix)) {
            throw new \RuntimeException(
                'DataCite IGSN prefix is not configured. Please set igsn_prefix in config/datacite.php.'
            );
        }

        $this->client = Http::withHeaders([
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

                    if (class_exists(\GuzzleHttp\Exception\ConnectException::class)
                        && $exception instanceof \GuzzleHttp\Exception\ConnectException) {
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
}
