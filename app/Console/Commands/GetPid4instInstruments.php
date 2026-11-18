<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pidinst\PidinstSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** @see https://docs.eudat.eu/b2inst/v3-rest/records/ */
#[Description('Synchronize public instruments from B2INST v3 and DataCite')]
#[Signature('get-pid4inst-instruments {--dry-run : Validate and report without publishing} {--refresh-caches : Retry cache refresh for the published snapshot}')]
class GetPid4instInstruments extends Command
{
    public function handle(PidinstSyncService $service): int
    {
        try {
            if ($this->option('refresh-caches')) {
                if ($this->option('dry-run')) {
                    throw new \InvalidArgumentException('--dry-run and --refresh-caches cannot be combined.');
                }
                $service->retryCaches();
                $this->info('Cache refresh requested for the published PIDINST snapshot.');

                return self::SUCCESS;
            }
            $result = $service->sync((bool) $this->option('dry-run'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
