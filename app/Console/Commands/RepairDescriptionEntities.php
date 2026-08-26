<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Descriptions\DescriptionEntityRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and repair encoded angle brackets in imported DataCite descriptions.')]
#[Signature('descriptions:repair-entities
    {--apply : Persist changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this description ID}
    {--limit=0 : Maximum number of descriptions to scan; zero means all}
    {--doi=* : Restrict the run to one or more DOI resources}
    {--report= : Optional CSV report path}')]
final class RepairDescriptionEntities extends Command
{
    public function __construct(private readonly DescriptionEntityRepairService $repair)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->repair->run(
            apply: (bool) $this->option('apply'),
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
        );

        $this->info($this->option('apply') ? 'Description entity repair applied.' : 'Dry run only; no data was changed.');
        $this->table(['Scanned', 'Changed', 'Unchanged', 'Skipped', 'Errors'], [[
            $result['scanned'],
            $result['changed'],
            $result['unchanged'],
            $result['skipped'],
            $result['errors'],
        ]]);

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Repair report written to '.$reportPath);
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<array{description_id: int, resource_id: int, doi: string, status: string, replacements: int, message: string}>  $rows
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
            fputcsv($stream, ['description_id', 'resource_id', 'doi', 'status', 'replacements', 'message']);
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['description_id'],
                    $row['resource_id'],
                    $row['doi'],
                    $row['status'],
                    $row['replacements'],
                    $row['message'],
                ]);
            }
        } finally {
            fclose($stream);
        }
    }
}
