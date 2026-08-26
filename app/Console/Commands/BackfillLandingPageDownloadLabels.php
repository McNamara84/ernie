<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LegacyDownloadLabelBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and backfill landing-page download labels from SUMARIOPMD visible names.')]
#[Signature('landing-pages:backfill-download-labels
    {--apply : Persist safe changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this landing page ID}
    {--limit=0 : Maximum number of landing pages; zero means all}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--report= : Optional CSV report path}')]
final class BackfillLandingPageDownloadLabels extends Command
{
    public function __construct(private readonly LegacyDownloadLabelBackfillService $backfill)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $result = $this->backfill->run(
            apply: $apply,
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
        );

        $this->info($apply ? 'Download label backfill applied.' : 'Dry run only; no data was changed.');
        $this->table(
            ['Scanned', 'Primary', 'Files', 'Links', 'Preserved', 'Unmatched', 'Errors'],
            [[
                $result['scanned'],
                $result['primary_labels_updated'],
                $result['file_labels_updated'],
                $result['link_labels_updated'],
                $result['preserved_labels'],
                $result['unmatched_urls'],
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

    /** @param list<array{landing_page_id: int, resource_id: int, doi: string, status: string, message: string}> $rows */
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
            fputcsv($stream, ['landing_page_id', 'resource_id', 'doi', 'status', 'message']);
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['landing_page_id'],
                    $row['resource_id'],
                    $row['doi'],
                    $row['status'],
                    $row['message'],
                ]);
            }
        } finally {
            fclose($stream);
        }
    }
}
