<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Legacy\LegacyTemporalCoverageBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and backfill temporal coverage from legacy SUMARIO resources.')]
#[Signature('resources:backfill-legacy-temporal-coverages
    {--apply : Persist safe changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of ERNIE resources; zero means all}
    {--chunk=100 : ERNIE resources per database batch (maximum 1000)}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--legacy-id=* : Restrict linked records to one or more SUMARIO resource IDs}
    {--match-by-doi : Also inspect older resources without a legacy_source_id by matching their DOI in SUMARIO}
    {--report= : Optional CSV report path}')]
final class BackfillLegacyTemporalCoverages extends Command
{
    public function __construct(private readonly LegacyTemporalCoverageBackfillService $backfill)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->backfill->run(
            apply: (bool) $this->option('apply'),
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(1000, (int) $this->option('chunk'))),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
            legacyIds: array_values(array_filter(
                array_map('intval', $this->option('legacy-id')),
                static fn (int $id): bool => $id > 0,
            )),
            matchByDoi: (bool) $this->option('match-by-doi'),
        );

        $this->info($this->option('apply')
            ? 'Legacy temporal coverage backfill applied.'
            : 'Dry run only; no data was changed.');
        $this->table(
            ['Scanned', 'Changed', 'Unchanged', 'No temporal', 'Missing legacy', 'Manual review', 'Errors', 'Updated coverages', 'Created coverages'],
            [[
                $result['scanned'],
                $result['changed'],
                $result['unchanged'],
                $result['no_temporal'],
                $result['missing_legacy'],
                $result['manual_review'],
                $result['errors'],
                $result['coverages_updated'],
                $result['coverages_created'],
            ]],
        );

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Backfill report written to '.$reportPath);
        }

        if ($result['manual_review'] > 0) {
            $this->warn('Some resources need manual review; inspect the CSV report before retrying them.');
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<array{resource_id: int, doi: string, legacy_resource_id: int|null, match_method: string, status: string, temporal_coverages: int, coverages_updated: int, coverages_created: int, warnings: int, message: string}>  $rows
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
                'legacy_resource_id',
                'match_method',
                'status',
                'temporal_coverages',
                'coverages_updated',
                'coverages_created',
                'warnings',
                'message',
            ]);

            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['resource_id'],
                    $row['doi'],
                    $row['legacy_resource_id'],
                    $row['match_method'],
                    $row['status'],
                    $row['temporal_coverages'],
                    $row['coverages_updated'],
                    $row['coverages_created'],
                    $row['warnings'],
                    $row['message'],
                ]);
            }
        } finally {
            fclose($stream);
        }
    }
}
