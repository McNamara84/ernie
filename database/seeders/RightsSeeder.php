<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeder for Rights / Licenses (DataCite #16)
 *
 * Syncs SPDX licenses from the official SPDX license list.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/rights/
 * @see https://spdx.org/licenses/
 */
class RightsSeeder extends Seeder
{
    /**
     * Seed SPDX licenses into the rights table.
     */
    public function run(): void
    {
        Artisan::call('spdx:sync-licenses');
    }
}
