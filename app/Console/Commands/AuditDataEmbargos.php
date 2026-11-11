<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Models\Resource;
use App\Services\EmbargoService;
use Illuminate\Console\Command;

final class AuditDataEmbargos extends Command
{
    protected $signature = 'embargo:audit {--apply : Normalize future Available access levels without publishing landing pages}';

    protected $description = 'Audit inconsistent data embargo dates, external pages and pending registrations';

    public function handle(EmbargoService $policy): int
    {
        $issues = 0;
        Resource::query()->with(['dates.dateType', 'landingPage'])->chunkById(200, function ($resources) use ($policy, &$issues): void {
            foreach ($resources as $resource) {
                if (! $policy->isEmbargoed($resource)) {
                    continue;
                }
                $problems = [];
                if ($policy->availableDate($resource) === null) {
                    $problems[] = 'invalid or ambiguous Available date';
                }
                if ($resource->landingPage === null) {
                    $problems[] = 'missing landing page (requires deliberate setup)';
                } elseif ($resource->landingPage->isExternal()) {
                    $problems[] = 'external landing page (requires internal template)';
                }
                if ($resource->embargo_registration_started_at !== null) {
                    $problems[] = 'pending DataCite create (reconcile before retrying)';
                }
                if ($resource->access_level !== AccessLevel::EMBARGOED) {
                    $problems[] = 'future Available requires Embargoed access';
                    if ($this->option('apply')) {
                        $policy->normalizeResource($resource);
                    }
                }
                if ($problems !== []) {
                    $issues++;
                    $this->line('#'.$resource->id.' '.implode('; ', $problems));
                }
            }
        });
        $this->info($issues.' resources require review. No landing pages were published.');

        return self::SUCCESS;
    }
}
