<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use App\Services\B2inst\B2instClient;
use App\Services\Pidinst\DataCiteInstrumentClient;
use Illuminate\Support\Facades\Storage;

/**
 * Compare each registry's raw count independently of the deduplicated selection.
 */
class Pid4instStatusService
{
    /**
     * Get the local status of a PID setting (from stored JSON file).
     *
     * @return array{exists: bool, itemCount: int, lastUpdated: string|null, serverCount: int|null, sourceCounts?: array<string, int>, excludedCount?: int, exclusions?: list<array{id: string, reasonCode: string, source?: string}>, refreshPending?: bool}
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

        /** @var array{lastUpdated?: string, total?: int, data?: list<mixed>, _sync?: array<string, mixed>}|null $data */
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
        $sourceCounts = [];
        foreach (['b2inst', 'datacite'] as $source) {
            $count = data_get($data, '_sync.sources.'.$source.'.serverCount');
            if (is_int($count) && $count >= 0) {
                $sourceCounts[$source] = $count;
            }
        }

        return [
            'exists' => true,
            'itemCount' => $data['total'] ?? count($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
            'serverCount' => is_int($serverCount) && $serverCount >= 0 ? $serverCount : null,
            'sourceCounts' => $sourceCounts,
            ...Pid4instUpdateDiagnosticsService::fromSnapshot($data),
        ];
    }

    /**
     * Sum raw source counts, including overlaps and records later excluded.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteCount(): int
    {
        return array_sum($this->getRemoteCounts());
    }

    /** @return array{b2inst: int, datacite: int} */
    public function getRemoteCounts(): array
    {
        return ['b2inst' => app(B2instClient::class)->count(), 'datacite' => app(DataCiteInstrumentClient::class)->count()];
    }

    /**
     * Compare local and remote instrument counts.
     *
     * An update is considered available when the remote count differs
     * from the last synchronized server count, including excluded records.
     * A legacy snapshot needs an initial combined refresh even at equal counts.
     *
     * Equal counts do not establish metadata freshness.
     *
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null, comparisonBasis: string, sources: array<string, array{syncedCount: int|null, remoteCount: int}>}
     *
     * @throws \RuntimeException If the API request fails
     */
    public function compareWithRemote(PidSetting $setting): array
    {
        $localStatus = $this->getLocalStatus($setting);
        $remoteCounts = $this->getRemoteCounts();
        $sources = [];
        $updateAvailable = false;
        foreach ($remoteCounts as $source => $remoteCount) {
            $syncedCount = $localStatus['sourceCounts'][$source] ?? null;
            $sources[$source] = ['syncedCount' => $syncedCount, 'remoteCount' => $remoteCount];
            $updateAvailable = $updateAvailable || $syncedCount !== $remoteCount;
        }

        return [
            'localCount' => $localStatus['itemCount'],
            'remoteCount' => array_sum($remoteCounts),
            'updateAvailable' => $updateAvailable,
            'lastUpdated' => $localStatus['lastUpdated'],
            'comparisonBasis' => 'count',
            'sources' => $sources,
        ];
    }
}
