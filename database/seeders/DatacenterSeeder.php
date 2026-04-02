<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Datacenter;
use Illuminate\Database\Seeder;

class DatacenterSeeder extends Seeder
{
    /**
     * Initial datacenters for GFZ resource categorization.
     *
     * @var list<string>
     */
    private const DATACENTERS = [
        'ArboDat 2016',
        'CRC1211DB CRC 1211 Database',
        'DEKORP - German Continental Seismic Reflection Program',
        'DIGIS Geochemical Data for GEOROC 2.0',
        'EnMAP',
        'FID GEO',
        'GEOFON Seismic Events',
        'GEOFON Seismic Networks',
        'GFZ German Research Centre for Geosciences',
        'GIPP Geophysical Instrument Pool Potsdam',
        'ICGEM International Centre for Global Earth Models',
        'IGETS International Geodynamics and Earth Tide Service',
        'INTERMAGNET',
        'ISDC Information System and Data Center',
        'ISG International Service for the Geoid',
        'PIK Potsdam Institute for Climate Impact Research',
        'Riesgos',
        'SDDB Scientific Drilling Database',
        'SFB806 and CRC806-Database',
        'SPP 2238 - Dynamics of Ore Metals Enrichment - DOME',
        'TERENO',
        'TR32DB CRC/Transregio 32 Database',
        'TRR228DB CRC/Transregio 228 Database',
        'WDS World Stress Map',
    ];

    public function run(): void
    {
        foreach (self::DATACENTERS as $name) {
            Datacenter::firstOrCreate(['name' => $name]);
        }
    }
}
