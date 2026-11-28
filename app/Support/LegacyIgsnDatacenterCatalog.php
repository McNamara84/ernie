<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Datacenter;

/** Local datacenter names retained for the migrated IGSN catalogue. */
final class LegacyIgsnDatacenterCatalog
{
    /** @return list<string> */
    public static function canonicalNames(): array
    {
        return [
            'AWI: Polar Terrestrial Environmental Systems',
            'Earth Shape SPP',
            'Geothermal Energy Systems',
            Datacenter::GFZ_NAME,
            'Expedition database Hereon',
            'High Latitude Lakes',
            'ICDP',
            'Medusa',
            'Sonne273',
        ];
    }
}
