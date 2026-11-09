<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use App\Services\DcatAp\DcatApReadinessService;
use App\Services\DcatAp\DcatApRecordService;
use Illuminate\Console\Command;

final class SyncDcatApRecords extends Command
{
    protected $signature = 'dcat-ap:sync';

    protected $description = 'Reconcile DCAT-AP harvest history after enabling the format or changing its revision';

    public function handle(DcatApRecordService $records, DcatApReadinessService $readiness): int
    {
        if (! config('dcatap.enabled')) {
            $this->error('Enable DCATAP_ENABLED before synchronizing the format history.');

            return self::FAILURE;
        }
        $readiness->validateConfiguration();
        Resource::query()->select('id')->chunkById(200, function ($resources) use ($records): void {
            foreach ($resources as $resource) {
                $records->reconcile($resource->id);
            }
        });
        OaiPmhFormatRecord::where('metadata_prefix', 'dcat_ap')->where('status', 'active')->whereDoesntHave('resource')
            ->chunkById(200, function ($history) use ($records): void {
                foreach ($history as $record) {
                    $records->retireOrphan($record->id);
                }
            });
        $this->info('DCAT-AP harvest history synchronized.');

        return self::SUCCESS;
    }
}
