<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking PID4INST instrument status and comparing with b2inst API.
 */
class Pid4instStatusService
{
    /**
     * Get the local status of a PID setting (from stored JSON file).
     *
     * @return array{exists: bool, instrumentCount: int, lastUpdated: string|null}
     */
    public function getLocalStatus(PidSetting $setting): array
    {
        $filePath = $setting->getFilePath();

        if (! Storage::exists($filePath)) {
            return [
                'exists' => false,
                'instrumentCount' => 0,
                'lastUpdated' => null,
            ];
        }

        $content = Storage::get($filePath);

        if ($content === null || $content === '') {
            return [
                'exists' => false,
                'instrumentCount' => 0,
                'lastUpdated' => null,
            ];
        }

        /** @var array{lastUpdated?: string, total?: int, data?: list<mixed>}|null $data */
        $data = json_decode($content, true);

        if ($data === null) {
            return [
                'exists' => false,
                'instrumentCount' => 0,
                'lastUpdated' => null,
            ];
        }

        return [
            'exists' => true,
            'instrumentCount' => $data['total'] ?? count($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
        ];
    }

    /**
     * Get the instrument count from the b2inst API.
     *
     * Makes a lightweight request (size=1) to get the total hits count
     * without downloading all instruments.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteCount(): int
    {
        /** @var string $host */
        $host = config('b2inst.host');

        $response = Http::timeout(30)
            ->accept('application/json')
            ->get("{$host}/api/records/", [
                'size' => 1,
                'page' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch from b2inst API: HTTP {$response->status()}"
            );
        }

        return (int) $response->json('hits.total', 0);
    }

    /**
     * Compare local and remote instrument counts.
     *
     * An update is considered available when the remote count differs
     * from the local count (instruments can be added or removed).
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
            'localCount' => $localStatus['instrumentCount'],
            'remoteCount' => $remoteCount,
            'updateAvailable' => $remoteCount !== $localStatus['instrumentCount'],
            'lastUpdated' => $localStatus['lastUpdated'],
        ];
    }
}
