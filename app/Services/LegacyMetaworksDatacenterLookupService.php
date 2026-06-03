<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Datacenter;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyMetaworksDatacenterLookupService
{
    public const DEFAULT_DATACENTER = 'GFZ German Research Centre for Geosciences';
    public const GIPP_DATACENTER = 'GIPP Geophysical Instrument Pool Potsdam';
    public const SDDB_DATACENTER = 'SDDB Scientific Drilling Database';

    private const CONNECTION = 'legacy_metaworks';

    /**
     * @return list<string>
     */
    public function resolveDatacenterNames(string $doi): array
    {
        try {
            $datacenters = [];

            if ($this->doiExistsInTable('gipp_dataset', $doi)) {
                $datacenters[] = self::GIPP_DATACENTER;
            }

            if ($this->doiExistsInTable('sddb_dataset', $doi)) {
                $datacenters[] = self::SDDB_DATACENTER;
            }

            return $datacenters !== [] ? $datacenters : [self::DEFAULT_DATACENTER];
        } catch (\Throwable $exception) {
            Log::warning('Legacy Metaworks datacenter lookup failed; using default datacenter', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            return [self::DEFAULT_DATACENTER];
        }
    }

    /**
     * @return list<int>
     */
    public function resolveDatacenterIds(string $doi): array
    {
        $names = $this->resolveDatacenterNames($doi);

        $ids = Datacenter::query()
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }

    public function syncDatacenters(Resource $resource, string $doi): void
    {
        $datacenterIds = $this->resolveDatacenterIds($doi);

        if ($datacenterIds === []) {
            Log::warning('No matching ERNIE datacenter found for legacy import', [
                'doi' => $doi,
                'expected_datacenters' => $this->resolveDatacenterNames($doi),
            ]);

            return;
        }

        $changes = $resource->datacenters()->sync($datacenterIds);

        if (array_filter($changes)) {
            $resource->touch();
        }
    }

    private function doiExistsInTable(string $table, string $doi): bool
    {
        return DB::connection(self::CONNECTION)
            ->table($table)
            ->where('doi', $doi)
            ->exists();
    }
}
