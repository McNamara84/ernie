<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking RAiD (Research Activity Identifier) status
 * and comparing the local cache with public DataCite RAiD metadata.
 */
class RaidStatusService
{
    /**
     * Get the local status of the RAiD data (from stored JSON file).
     *
     * @return array{exists: bool, itemCount: int, lastUpdated: string|null}
     */
    public function getLocalStatus(PidSetting $setting): array
    {
        $filePath = $setting->getFilePath();

        if (! Storage::exists($filePath)) {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
            ];
        }

        $content = Storage::get($filePath);

        if ($content === null || $content === '') {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
            ];
        }

        /** @var array{lastUpdated?: string, total?: int, data?: list<mixed>}|null $data */
        $data = json_decode($content, true);

        if ($data === null) {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
            ];
        }

        return [
            'exists' => true,
            'itemCount' => $data['total'] ?? count($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
        ];
    }

    /**
     * Get the public RAiD count from DataCite.
     *
     * Makes a lightweight request (page size 1) to get the total result count
     * without downloading all RAiD project records.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteCount(): int
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->get($this->dataCiteEndpoint().'/dois', [
                'query' => $this->searchQuery(),
                'page[size]' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch from DataCite RAiD search: HTTP {$response->status()}"
            );
        }

        return (int) $response->json('meta.total', 0);
    }

    /**
     * Compare local and remote RAiD counts.
     *
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null}
     *
     * @throws \RuntimeException If the API request fails
     */
    public function compareWithRemote(PidSetting $setting): array
    {
        $localStatus = $this->getLocalStatus($setting);
        $remoteCount = $this->getRemoteCount();

        return [
            'localCount' => $localStatus['itemCount'],
            'remoteCount' => $remoteCount,
            'updateAvailable' => $remoteCount !== $localStatus['itemCount'],
            'lastUpdated' => $localStatus['lastUpdated'],
        ];
    }

    private function dataCiteEndpoint(): string
    {
        return rtrim((string) config('raid.datacite_endpoint'), '/');
    }

    private function searchQuery(): string
    {
        return (string) config('raid.search_query');
    }
}
