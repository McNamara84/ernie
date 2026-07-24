<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Datacenter;

final class LegacyIgsnDatacenterCatalog
{
    /**
     * @var array<string, array{legacy_name: string, name: string}>
     */
    private const DATACENTERS = [
        'IGSNDB.AWIENV' => [
            'legacy_name' => 'AWI: Polar Terrestrial Environmental Systems',
            'name' => 'AWI: Polar Terrestrial Environmental Systems',
        ],
        'IGSNDB.ESP' => [
            'legacy_name' => 'Earth Shape SPP',
            'name' => 'Earth Shape SPP',
        ],
        'IGSNDB.GES' => [
            'legacy_name' => 'Geothermal Energy Systems',
            'name' => 'Geothermal Energy Systems',
        ],
        'IGSNDB.GFZ' => [
            'legacy_name' => 'GFZ Potsdam',
            'name' => Datacenter::GFZ_NAME,
        ],
        'IGSNDB.HEREON' => [
            'legacy_name' => 'Expedition database Hereon',
            'name' => 'Expedition database Hereon',
        ],
        'IGSNDB.HLL' => [
            'legacy_name' => 'High Latitude Lakes',
            'name' => 'High Latitude Lakes',
        ],
        'IGSNDB.ICDP' => [
            'legacy_name' => 'ICDP',
            'name' => 'ICDP',
        ],
        'IGSNDB.MEDUSA' => [
            'legacy_name' => 'Medusa',
            'name' => 'Medusa',
        ],
        'IGSNDB.SO273' => [
            'legacy_name' => 'Sonne273',
            'name' => 'Sonne273',
        ],
    ];

    /**
     * @return array<string, array{legacy_name: string, name: string}>
     */
    public static function all(): array
    {
        return self::DATACENTERS;
    }

    /**
     * @return array{legacy_name: string, name: string}|null
     */
    public static function find(string $legacyId): ?array
    {
        return self::DATACENTERS[trim($legacyId)] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function canonicalNames(): array
    {
        return array_values(array_unique(array_column(self::DATACENTERS, 'name')));
    }

    public static function facetValue(string $legacyId): ?string
    {
        $entry = self::find($legacyId);

        return $entry === null
            ? null
            : trim($legacyId).' - '.$entry['legacy_name'];
    }

    /**
     * @return array{id: string, legacy_name: string, name: string}|null
     */
    public static function parseFacetValue(string $facetValue): ?array
    {
        $parts = explode(' - ', trim($facetValue), 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$legacyId, $legacyName] = array_map('trim', $parts);
        $entry = self::find($legacyId);

        if ($entry === null || ! hash_equals($entry['legacy_name'], $legacyName)) {
            return null;
        }

        return [
            'id' => $legacyId,
            'legacy_name' => $entry['legacy_name'],
            'name' => $entry['name'],
        ];
    }
}
