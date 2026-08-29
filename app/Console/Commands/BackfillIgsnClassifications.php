<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Igsn\IgsnClassificationBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and add missing legacy IGSN classifications for Issue #1210.')]
#[Signature('igsn:backfill-classifications
    {--apply : Persist changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this resource ID}
    {--limit=0 : Maximum number of resources; zero means all}
    {--chunk=100 : IGSNs per legacy portal request (maximum 100)}
    {--doi=* : Restrict the run to one or more IGSN DOIs or handles}
    {--report= : Optional CSV report path}')]
final class BackfillIgsnClassifications extends Command
{
    public function __construct(private readonly IgsnClassificationBackfillService $backfill)
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

        $this->info($this->option('apply') ? 'IGSN classification backfill applied.' : 'Dry run only; no data was changed.');
        $this->table(
            ['Scanned', 'Changed', 'Unchanged', 'Inserted', 'Types filled', 'Missing DIF', 'Rejected', 'Conflicts', 'Errors'],
            [[
                $result['scanned'],
                $result['changed'],
                $result['unchanged'],
                $result['inserted'],
                $result['types_filled'],
                $result['missing_dif'],
                $result['rejected'],
                $result['conflicts'],
                $result['errors'],
            ]],
        );

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Backfill report written to '.$reportPath);
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<array{
     *     resource_id: int,
     *     doi: string,
     *     handle: string,
     *     status: string,
     *     existing_values: string,
     *     source_values: string,
     *     inserted_values: string,
     *     types_filled: string,
     *     rejected_values: string,
     *     conflicts: string,
     *     message: string
     * }>  $rows
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
            fputcsv($stream, [
                'resource_id',
                'doi',
                'handle',
                'status',
                'existing_values',
                'source_values',
                'inserted_values',
                'types_filled',
                'rejected_values',
                'conflicts',
                'message',
            ]);

            foreach ($rows as $row) {
                fputcsv($stream, array_values($row));
            }
        } finally {
            fclose($stream);
        }
    }
}
