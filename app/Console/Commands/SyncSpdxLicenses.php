<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncSpdxLicenses extends Command
{
    protected $signature = 'spdx:sync-licenses';

    protected $description = 'Sync licenses from SPDX and store them in the database';

    public function handle(): int
    {
        try {
            $response = Http::get('https://spdx.org/licenses/licenses.json');
        } catch (Throwable $e) {
            $this->error('Failed to fetch SPDX licenses: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error(
                sprintf(
                    'Failed to fetch SPDX licenses: HTTP %s %s',
                    $response->status(),
                    $response->body()
                )
            );

            return self::FAILURE;
        }

        $licenses = $response->json('licenses') ?? [];

        foreach ($licenses as $license) {
            License::updateOrCreate(
                ['identifier' => $license['licenseId']],
                [
                    'name' => $license['name'],
                    'spdx_id' => $license['licenseId'],
                    'reference' => $license['reference'] ?? null,
                    'details_url' => $license['detailsUrl'] ?? null,
                    'is_deprecated_license_id' => $license['isDeprecatedLicenseId'] ?? false,
                    'is_osi_approved' => $license['isOsiApproved'] ?? false,
                    'is_fsf_libre' => $license['isFsfLibre'] ?? false,
                ]
            );
        }

        $this->info('SPDX licenses synced: '.count($licenses));

        return self::SUCCESS;
    }
}
