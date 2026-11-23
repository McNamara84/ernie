<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DcatApFormat;
use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use App\Services\DcatAp\DcatApReadinessService;
use App\Services\DcatAp\DcatApRecordService;
use Illuminate\Console\Command;

final class SyncDcatApRecords extends Command
{
    protected $signature = 'dcat-ap:sync {--profile=dcat_ap : dcat_ap or epos_dcat_ap}';

    protected $description = 'Reconcile DCAT-AP harvest history after enabling the format or changing its revision';

    public function handle(DcatApRecordService $records, DcatApReadinessService $readiness): int
    {
        $format = DcatApFormat::tryFrom((string) $this->option('profile'));
        if ($format === null || ! $format->enabled()) {
            $this->error('Select an enabled DCAT-AP profile before synchronizing the format history.');

            return self::FAILURE;
        }
        $readiness->validateConfiguration();
        Resource::query()->select('id')->chunkById(200, function ($resources) use ($records, $format): void {
            foreach ($resources as $resource) {
                $records->reconcile($resource->id, format: $format);
            }
        });
        OaiPmhFormatRecord::where('metadata_prefix', $format->value)->where('status', 'active')->whereDoesntHave('resource')
            ->chunkById(200, function ($history) use ($records, $format): void {
                foreach ($history as $record) {
                    $records->retireOrphan($record->id, $format);
                }
            });
        $this->info($format->label().' harvest history synchronized.');

        return self::SUCCESS;
    }
}
