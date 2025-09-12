<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncSpdxLicenses extends Command
{
    protected $signature = 'spdx:sync-licenses';

    protected $description = 'Sync licenses from SPDX and store them in the database';

    public function handle(): void
    {
        $response = Http::get('https://spdx.org/licenses/licenses.json');

        if ($response->failed()) {
            $this->error('Failed to fetch SPDX licenses');
            return;
        }

        $licenses = $response->json('licenses') ?? [];

        foreach ($licenses as $license) {
            License::updateOrCreate(
                ['identifier' => $license['licenseId']],
                ['name' => $license['name']]
            );
        }

        $this->info('SPDX licenses synced: '.count($licenses));
    }
}

