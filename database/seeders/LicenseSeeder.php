<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class LicenseSeeder extends Seeder
{
    /**
     * Seed SPDX licenses into the database.
     */
    public function run(): void
    {
        Artisan::call('spdx:sync-licenses');
    }
}
