<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Igsn\IgsnDescriptionBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and backfill structured legacy IGSN descriptions and locality descriptions for Issue #1167.')]
#[Signature('igsn:backfill-descriptions
    {--apply : Persist changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this resource ID}
    {--limit=0 : Maximum number of resources; zero means all}
    {--chunk=100 : IGSNs per legacy portal request (maximum 100)}
    {--doi=* : Restrict the run to one or more IGSN DOIs or handles}
    {--report= : Optional CSV report path}')]
final class BackfillIgsnDescriptions extends Command
{
    public function __construct(private readonly IgsnDescriptionBackfillService $backfill)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->backfill->run(
            apply: (bool) $this->option('apply'),
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(100, (int) $this->option('chunk'))),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
        );

        $this->info($this->option('apply') ? 'IGSN description backfill applied.' : 'Dry run only; no data was changed.');
        $this->table(['Scanned', 'Changed', 'Unchanged', 'Missing DIF', 'Errors'], [[
            $result['scanned'],
            $result['changed'],
            $result['unchanged'],
            $result['missing_dif'],
            $result['errors'],
        ]]);

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Backfill report written to '.$reportPath);
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<array{resource_id: int, doi: string, handle: string, status: string, descriptions_changed: bool, locality_changed: bool, message: string}>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create report directory: '.$directory);
        }

        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to write report: '.$path);
        }

        try {
            fputcsv($stream, ['resource_id', 'doi', 'handle', 'status', 'descriptions_changed', 'locality_changed', 'message']);
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['resource_id'],
                    $row['doi'],
                    $row['handle'],
                    $row['status'],
                    $row['descriptions_changed'] ? 'yes' : 'no',
                    $row['locality_changed'] ? 'yes' : 'no',
                    $row['message'],
                ]);
            }
        } finally {
            fclose($stream);
        }
    }
}
