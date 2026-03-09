<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Service for fetching data from the ARDC Linked Data API.
 *
 * Handles paginated retrieval and response validation for the
 * ICS Chronostratigraphic Timescale vocabulary.
 */
class ArdcApiService
{
    private const BASE_URL = 'https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json';

    private const PAGE_SIZE = 200;

    /**
     * Fetch all items from the ARDC API across all pages.
     *
     * @param  int  $timeout  HTTP timeout in seconds
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException If the API request fails or returns an unexpected format
     */
    public function fetchAllItems(int $timeout = 60): array
    {
        $allItems = [];
        $page = 0;

        do {
            $url = self::BASE_URL.'?_pageSize='.self::PAGE_SIZE.'&_page='.$page;

            $response = Http::timeout($timeout)
                ->accept('application/json')
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch from ARDC API: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['result']['items']) || ! is_array($data['result']['items'])) {
                throw new RuntimeException(
                    'Unexpected ARDC API response format: missing result.items array'
                );
            }

            $items = $data['result']['items'];
            $allItems = array_merge($allItems, $items);

            $hasNextPage = isset($data['result']['next']);
            $page++;
        } while ($hasNextPage && count($items) > 0);

        return $allItems;
    }
}
