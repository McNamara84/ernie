<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Datacenter;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyMetaworksDatacenterLookupService
{
    public const ARBODAT_DATACENTER = 'ArboDat 2016';

    public const CRC1211DB_DATACENTER = 'CRC1211DB CRC 1211 Database';

    public const DEKORP_DATACENTER = 'DEKORP - German Continental Seismic Reflection Program';

    public const DIGIS_DATACENTER = 'DIGIS Geochemical Data for GEOROC 2.0';

    public const ENMAP_DATACENTER = 'EnMAP';

    public const FID_GEO_DATACENTER = 'FID GEO';

    public const DEFAULT_DATACENTER = 'GFZ German Research Centre for Geosciences';

    public const GIPP_DATACENTER = 'GIPP Geophysical Instrument Pool Potsdam';

    public const ICGEM_DATACENTER = 'ICGEM International Centre for Global Earth Models';

    public const IGETS_DATACENTER = 'IGETS International Geodynamics and Earth Tide Service';

    public const INTERMAGNET_DATACENTER = 'INTERMAGNET';

    public const ISDC_DATACENTER = 'ISDC Information System and Data Center';

    public const ISG_DATACENTER = 'ISG International Service for the Geoid';

    public const PIK_DATACENTER = 'PIK Potsdam Institute for Climate Impact Research';

    public const RIESGOS_DATACENTER = 'Riesgos';

    public const SDDB_DATACENTER = 'SDDB Scientific Drilling Database';

    public const SFB806_DATACENTER = 'SFB806 and CRC806-Database';

    public const SPP2238_DATACENTER = 'SPP 2238 - Dynamics of Ore Metals Enrichment - DOME';

    public const TERENO_DATACENTER = 'TERENO';

    public const TR32DB_DATACENTER = 'TR32DB CRC/Transregio 32 Database';

    public const TRR228DB_DATACENTER = 'TRR228DB CRC/Transregio 228 Database';

    public const WDS_DATACENTER = 'WDS World Stress Map';

    private const CONNECTION = 'legacy_metaworks';

    /**
     * DOI suffix patterns that identify datacenters in legacy SUMARIO records.
     *
     * @var list<array{pattern: string, datacenters: list<string>}>
     */
    private const DOI_SUFFIX_DATACENTER_RULES = [
        [
            'pattern' => '/^ha[-_]?arbodat(?:[-_]|$)/',
            'datacenters' => [self::ARBODAT_DATACENTER],
        ],
        [
            'pattern' => '/^crc1211db(?:[._-]|$)/',
            'datacenters' => [self::CRC1211DB_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])dekorp(?:[.\/_-]|$)/',
            'datacenters' => [self::DEKORP_DATACENTER],
        ],
        [
            'pattern' => '/^digis(?:[._-]|[0-9]|$)/',
            'datacenters' => [self::DIGIS_DATACENTER],
        ],
        [
            'pattern' => '/^enmap(?:[._-]|$)/',
            'datacenters' => [self::ENMAP_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])fid(?:geo)?(?:[.\/_-]|$)/',
            'datacenters' => [self::FID_GEO_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])gipp(?:[.\/_-]|$)/',
            'datacenters' => [self::GIPP_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])icgem(?:[.\/_-]|$)/',
            'datacenters' => [self::ICGEM_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])igets(?:[.\/_-]|$)/',
            'datacenters' => [self::IGETS_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])intermagnet(?:[.\/_-]|$)/',
            'datacenters' => [self::INTERMAGNET_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])isdc(?:[.\/_-]|$)/',
            'datacenters' => [self::ISDC_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])isg(?:[.\/_-]|$)/',
            'datacenters' => [self::ISG_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])pik(?:[.\/_-]|$)/',
            'datacenters' => [self::PIK_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])riesgos(?:[.\/_-]|$)/',
            'datacenters' => [self::RIESGOS_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])sddb(?:[.\/_-]|$)/',
            'datacenters' => [self::SDDB_DATACENTER],
        ],
        [
            'pattern' => '/^(?:sfb806|crc806)(?:[._-]|$)/',
            'datacenters' => [self::SFB806_DATACENTER],
        ],
        [
            'pattern' => '/^spp[-_]?2238(?:[._-]|$)/',
            'datacenters' => [self::SPP2238_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])tereno(?:[.\/_-]|$)/',
            'datacenters' => [self::TERENO_DATACENTER],
        ],
        [
            'pattern' => '/^tr32db(?:[._-]|$)/',
            'datacenters' => [self::TR32DB_DATACENTER],
        ],
        [
            'pattern' => '/^trr228db(?:[._-]|$)/',
            'datacenters' => [self::TRR228DB_DATACENTER],
        ],
        [
            'pattern' => '/(?:^|[.\/_-])w(?:ds|sm)(?:[.\/_-]|$)/',
            'datacenters' => [self::WDS_DATACENTER],
        ],
    ];

    /**
     * @return list<string>
     */
    public function resolveDatacenterNames(string $doi): array
    {
        $normalizedDoi = $this->normalizeDoi($doi);
        $patternDatacenters = $this->resolveDatacenterNamesFromDoiPattern($normalizedDoi);

        try {
            $datacenters = $patternDatacenters;

            if ($this->doiExistsInTable('gipp_dataset', $normalizedDoi)) {
                $datacenters[] = self::GIPP_DATACENTER;
            }

            if ($this->doiExistsInTable('sddb_dataset', $normalizedDoi)) {
                $datacenters[] = self::SDDB_DATACENTER;
            }

            $datacenters = $this->uniqueDatacenters($datacenters);

            return $datacenters !== [] ? $datacenters : [self::DEFAULT_DATACENTER];
        } catch (\Throwable $exception) {
            Log::warning('Legacy Metaworks datacenter lookup failed; using DOI pattern or default datacenter', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            return $patternDatacenters !== [] ? $patternDatacenters : [self::DEFAULT_DATACENTER];
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

    /**
     * @return list<string>
     */
    private function resolveDatacenterNamesFromDoiPattern(string $doi): array
    {
        $suffix = $this->doiSuffix($doi);

        if ($suffix === null) {
            return [];
        }

        $datacenters = [];

        foreach (self::DOI_SUFFIX_DATACENTER_RULES as $rule) {
            if (preg_match($rule['pattern'], $suffix) !== 1) {
                continue;
            }

            array_push($datacenters, ...$rule['datacenters']);
        }

        return $this->uniqueDatacenters($datacenters);
    }

    private function normalizeDoi(string $doi): string
    {
        $doi = trim($doi);

        $doi = preg_replace('/^(?:https?:\/\/(?:dx\.)?doi\.org\/|doi:)/i', '', $doi) ?? $doi;

        return trim($doi);
    }

    private function doiSuffix(string $doi): ?string
    {
        $doi = strtolower($doi);

        if (preg_match('/^10\.[^\/]+\/+(.+)$/', $doi, $matches) !== 1) {
            return null;
        }

        $suffix = ltrim((string) $matches[1], '/');

        return $suffix !== '' ? $suffix : null;
    }

    /**
     * @param  list<string>  $datacenters
     * @return list<string>
     */
    private function uniqueDatacenters(array $datacenters): array
    {
        return array_values(array_unique($datacenters));
    }
}
