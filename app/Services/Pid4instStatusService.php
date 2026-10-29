<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use App\Services\B2inst\B2instClient;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking PID4INST instrument status and comparing with b2inst API.
 */
class Pid4instStatusService
{
    /**
     * Get the local status of a PID setting (from stored JSON file).
     *
     * @return array{exists: bool, itemCount: int, lastUpdated: string|null, serverCount: int|null}
     */
    public function getLocalStatus(PidSetting $setting): array
    {
        $filePath = $setting->getFilePath();

        if (! Storage::exists($filePath)) {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
                'serverCount' => null,
            ];
        }

        $content = Storage::get($filePath);

        if ($content === null || $content === '') {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
                'serverCount' => null,
            ];
        }

        /** @var array{lastUpdated?: string, total?: int, data?: list<mixed>, _sync?: array{serverCount?: mixed}}|null $data */
        $data = json_decode($content, true);

        if (! is_array($data)) {
            return [
                'exists' => false,
                'itemCount' => 0,
                'lastUpdated' => null,
                'serverCount' => null,
            ];
        }

        $serverCount = data_get($data, '_sync.serverCount');

        return [
            'exists' => true,
            'itemCount' => $data['total'] ?? count($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
            'serverCount' => is_int($serverCount) && $serverCount >= 0 ? $serverCount : null,
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
        return app(B2instClient::class)->count();
    }

    /**
     * Compare local and remote instrument counts.
     *
     * An update is considered available when the remote count differs
     * from the last synchronized server count, including excluded records.
     * Legacy snapshots fall back to their offered instrument count.
     *
     * Equal counts do not establish metadata freshness.
     *
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null, comparisonBasis: string}
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
            'updateAvailable' => $remoteCount !== ($localStatus['serverCount'] ?? $localStatus['itemCount']),
            'lastUpdated' => $localStatus['lastUpdated'],
            'comparisonBasis' => 'count',
        ];
    }
}
