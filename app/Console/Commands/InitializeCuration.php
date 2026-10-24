<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Resource;
use App\Services\Curation\CurationService;
use Illuminate\Console\Command;

final class InitializeCuration extends Command
{
    protected $signature = 'curation:initialize {--apply : Start curation for unpublished existing resources}';

    protected $description = 'Preview or initialize curation without assigning historical ownership';

    public function handle(CurationService $curation): int
    {
        $counts = ['eligible' => 0, 'started' => 0, 'published' => 0, 'excluded' => 0, 'unassigned' => 0];
        Resource::with(CurationService::RELATIONS)->chunkById(200, function ($resources) use ($curation, &$counts): void {
            foreach ($resources as $resource) {
                if (! $curation->eligible($resource) || $resource->publicStatus() === 'dead') {
                    $counts['excluded']++;
                } elseif ($resource->curationState !== null) {
                    $counts['started']++;
                    $counts['unassigned'] += $resource->curationState->lead_curator_id === null ? 1 : 0;
                } elseif ($resource->publicStatus() === 'published') {
                    $counts['published']++;
                } else {
                    $counts['eligible']++;
                    $counts['unassigned']++;
                    if ($this->option('apply')) {
                        $curation->start($resource, null, 'backfill');
                    }
                }
            }
        });
        foreach ($counts as $name => $count) {
            $this->line($name.': '.$count);
        }
        $this->info($this->option('apply') ? 'Initialization completed. New entries are unassigned.' : 'Dry run. Use --apply after completing or pausing resource imports.');

        return self::SUCCESS;
    }
}
