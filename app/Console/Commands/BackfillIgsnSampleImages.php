<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Igsn\IgsnSampleImageBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and backfill sample images for IGSNs already stored in ERNIE.')]
#[Signature('igsn:backfill-images
    {--apply : Persist URLs and download managed GFZ images; otherwise perform a dry run}
    {--after-id=0 : Resume after this resource ID}
    {--limit=0 : Maximum number of resources; zero means all}
    {--chunk=100 : IGSNs per legacy portal request (maximum 100)}
    {--doi=* : Restrict the run to one or more IGSN DOIs or handles}
    {--report= : Optional CSV report path}
    {--force : Revalidate and replace images already processed successfully}')]
final class BackfillIgsnSampleImages extends Command
{
    public function __construct(private readonly IgsnSampleImageBackfillService $backfill)
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
            force: (bool) $this->option('force'),
        );

        $this->info($this->option('apply') ? 'IGSN sample image backfill applied.' : 'Dry run only; no data or files were changed.');
        $headers = [
            'Scanned', 'Would store', 'Stored', 'Would link', 'Linked', 'Unchanged',
            'No image', 'Placeholder', 'Missing DIF', 'Unsupported', 'Failed',
        ];
        $keys = [
            'scanned', 'would_store', 'stored', 'would_link_external', 'linked_external', 'unchanged',
            'no_image', 'invalid_placeholder', 'missing_dif', 'unsupported_source', 'failed',
        ];
        $this->table($headers, [[...array_map(static fn (string $key): int => (int) $result[$key], $keys)]]);

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Backfill report written to '.$reportPath);
        }

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
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
            fputcsv($stream, ['resource_id', 'doi', 'handle', 'status', 'message']);
            foreach ($rows as $row) {
                fputcsv($stream, [$row['resource_id'], $row['doi'], $row['handle'], $row['status'], $row['message']]);
            }
        } finally {
            fclose($stream);
        }
    }
}
