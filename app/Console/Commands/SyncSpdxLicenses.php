<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Right;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Description('Sync licenses from SPDX and store them in the rights table')]
#[Signature('spdx:sync-licenses')]
class SyncSpdxLicenses extends Command
{

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
            Right::updateOrCreate(
                ['identifier' => $license['licenseId']],
                [
                    'name' => $license['name'],
                    'uri' => $license['reference'] ?? null,
                    'scheme_uri' => 'https://spdx.org/licenses/',
                ]
            );
        }

        $this->info('SPDX licenses synced: '.count($licenses));

        return self::SUCCESS;
    }
}
