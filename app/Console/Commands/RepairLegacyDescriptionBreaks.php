<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Descriptions\LegacyDescriptionBreakCleanupService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

#[Description('Audit and repair duplicated line breaks in imported SUMARIO legacy descriptions.')]
#[Signature('resources:repair-legacy-description-breaks
    {--apply : Persist changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of ERNIE resources; zero means all}
    {--chunk=100 : ERNIE resources per database batch (maximum 1000)}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--legacy-id=* : Restrict linked records to one or more SUMARIO resource IDs}
    {--report= : Optional CSV report path}
    {--retry-sync= : Retry failed DataCite synchronization for a prior sync run ID}')]
final class RepairLegacyDescriptionBreaks extends Command
{
    public function __construct(
        private readonly LegacyDescriptionBreakCleanupService $cleanup,
        private readonly ImportedResourceDataCiteSyncDispatcherService $syncDispatcher,
        private readonly ImportProgressService $progressService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $retrySyncId = $this->option('retry-sync');
        if (is_string($retrySyncId) && trim($retrySyncId) !== '') {
            return $this->retrySync(trim($retrySyncId));
        }

        $result = $this->cleanup->run(
            apply: (bool) $this->option('apply'),
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(1000, (int) $this->option('chunk'))),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
            legacyIds: array_values(array_filter(
                array_map('intval', $this->option('legacy-id')),
                static fn (int $id): bool => $id > 0,
            )),
        );

        $this->info($this->option('apply')
            ? 'Legacy description break repair applied.'
            : 'Dry run only; no data was changed.');
        $this->table(
            ['Scanned', 'Legacy', 'Descriptions', 'Changed', 'Unchanged', 'Not legacy', 'Manual review', 'Concurrent', 'Errors', 'Breaks removed', 'Sync candidates'],
            [[
                $result['resources_scanned'],
                $result['legacy_resources'],
                $result['descriptions_scanned'],
                $result['changed'],
                $result['unchanged'],
                $result['not_legacy'],
                $result['manual_review'],
                $result['concurrent_changes'],
                $result['errors'],
                $result['breaks_removed'],
                count($result['sync_resource_ids']),
            ]],
        );

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Repair report written to '.$reportPath);
        }

        if ($result['manual_review'] > 0 || $result['concurrent_changes'] > 0) {
            $this->warn('Some resources were left unchanged and need review; inspect the CSV report.');
        }

        if ((bool) $this->option('apply') && $result['sync_resource_ids'] !== []) {
            $syncRunId = Str::uuid()->toString();
            $this->progressService->update(ImportProgressService::TYPE_RESOURCE, $syncRunId, [
                'status' => 'running',
                'phase' => 'syncing',
                'started_at' => now()->toIso8601String(),
            ]);
            $this->syncDispatcher->dispatch(
                ImportProgressService::TYPE_RESOURCE,
                $syncRunId,
                $result['sync_resource_ids'],
                fullMetadataResourceIds: $result['sync_resource_ids'],
            );
            $this->info('DataCite full-metadata sync run: '.$syncRunId);
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function retrySync(string $syncRunId): int
    {
        if (! Str::isUuid($syncRunId)) {
            $this->error('The sync run ID must be a UUID.');

            return Command::INVALID;
        }

        if (! $this->syncDispatcher->retryFailures(ImportProgressService::TYPE_RESOURCE, $syncRunId)) {
            $this->warn('No retryable DataCite synchronization failures were found.');

            return Command::FAILURE;
        }

        $this->info('Failed DataCite synchronizations were queued again.');

        return Command::SUCCESS;
    }

    /**
     * @param  list<array{resource_id: int, doi: string, legacy_resource_id: int|null, match_method: string, status: string, descriptions_scanned: int, descriptions_changed: int, breaks_removed: int, datacite_sync_status: string, message: string}>  $rows
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
                'descriptions_scanned',
                'descriptions_changed',
                'breaks_removed',
                'datacite_sync_status',
                'message',
            ], escape: '');

            foreach ($rows as $row) {
                fputcsv($stream, array_values($row), escape: '');
            }
        } finally {
            fclose($stream);
        }
    }
}
