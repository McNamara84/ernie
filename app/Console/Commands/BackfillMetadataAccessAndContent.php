<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MetadataAccessContentBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and backfill access levels plus URL-specific content descriptors for Issues #1042 and #1043.')]
#[Signature('metadata:backfill-access-content
    {--apply : Persist unambiguous changes; without this option the command is a dry run}
    {--report= : Optional CSV path for records requiring manual review}')]
final class BackfillMetadataAccessAndContent extends Command
{
    public function __construct(private readonly MetadataAccessContentBackfillService $backfill)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $result = $this->backfill->run($apply);

        $this->info($apply ? 'Backfill applied.' : 'Dry run only; no data was changed.');
        $this->table(['Access changes', 'Format changes', 'Size changes', 'Manual review'], [[
            $result['access_changes'],
            $result['format_changes'],
            $result['size_changes'],
            count($result['review']),
        ]]);

        if ($result['sample_access_counts'] !== []) {
            $this->newLine();
            $this->line('IGSN sample_access values:');
            $this->table(
                ['Value', 'Count'],
                collect($result['sample_access_counts'])
                    ->map(fn (int $count, string $value): array => [$value, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($result['review'] !== []) {
            $this->newLine();
            $this->warn('Records requiring manual review:');
            $this->table(['Resource', 'Category', 'Value', 'Detail'], array_map(
                static fn (array $row): array => [
                    $row['resource_id'],
                    $row['category'],
                    $row['value'],
                    $row['detail'],
                ],
                $result['review'],
            ));
        }

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['review']);
            $this->info('Review report written to '.$reportPath);
        }

        return Command::SUCCESS;
    }

    /** @param list<array{resource_id: int, category: string, value: string, detail: string}> $rows */
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
            fputcsv($stream, ['resource_id', 'category', 'value', 'detail']);
            foreach ($rows as $row) {
                fputcsv($stream, [$row['resource_id'], $row['category'], $row['value'], $row['detail']]);
            }
        } finally {
            fclose($stream);
        }
    }
}
