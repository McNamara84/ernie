<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking ROR (Research Organization Registry) status
 * and comparing with the ROR API v2.
 */
class RorStatusService
{
    private const ROR_API_URL = 'https://api.ror.org/v2/organizations';

    /**
     * Get the local status of the ROR data (from stored JSON file).
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
     * Get the organization count from the ROR API v2.
     *
     * Makes a lightweight request (page=1) to get the total count
     * without downloading all organizations.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteCount(): int
    {
        $response = Http::timeout(30)
            ->accept('application/json')
            ->get(self::ROR_API_URL, [
                'page' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch from ROR API: HTTP {$response->status()}"
            );
        }

        return (int) $response->json('number_of_results', 0);
    }

    /**
     * Compare local and remote organization counts.
     *
     * An update is considered available when the remote count differs
     * from the local count (organizations can be added or removed).
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
}
