<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Igsn\IgsnLegacyDifBackfillService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Description('Audit and add missing metadata from the legacy IGSN DIF service.')]
#[Signature('igsn:backfill-legacy-dif-metadata
    {--apply : Persist changes and automatically synchronize changed registered IGSNs with DataCite}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of resources; zero means all}
    {--chunk=100 : IGSNs per legacy portal request (maximum 100)}
    {--doi=* : Restrict the run to one or more IGSN DOIs or handles}
    {--datacenter=* : Restrict the run to one or more legacy datacenter codes}
    {--report= : Optional CSV report path}
    {--retry-sync= : Retry failed DataCite synchronization for a prior sync run UUID}')]
final class BackfillLegacyIgsnDifMetadata extends Command
{
    public function __construct(
        private readonly IgsnLegacyDifBackfillService $backfill,
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

        try {
            $result = $this->backfill->run(
                apply: (bool) $this->option('apply'),
                afterId: max(0, (int) $this->option('after-id')),
                limit: max(0, (int) $this->option('limit')),
                chunk: max(1, min(100, (int) $this->option('chunk'))),
                dois: array_values(array_filter($this->option('doi'), 'is_string')),
                datacenters: array_values(array_filter($this->option('datacenter'), 'is_string')),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $syncRunId = null;
        if ((bool) $this->option('apply') && $result['sync_resource_ids'] !== []) {
            $syncRunId = Str::uuid()->toString();
            $this->progressService->update(ImportProgressService::TYPE_IGSN, $syncRunId, [
                'status' => 'running',
                'phase' => 'syncing',
                'started_at' => now()->toIso8601String(),
            ]);
            $this->syncDispatcher->dispatch(
                ImportProgressService::TYPE_IGSN,
                $syncRunId,
                $result['sync_resource_ids'],
                fullMetadataResourceIds: $result['sync_resource_ids'],
            );
            foreach ($result['records'] as &$record) {
                if ($record['datacite_sync_status'] === 'pending') {
                    $record['sync_run_id'] = $syncRunId;
                    $record['datacite_sync_status'] = config('datacite.test_mode') !== false
                        ? 'skipped_test_mode'
                        : 'queued';
                }
            }
            unset($record);
        }

        $this->info((bool) $this->option('apply')
            ? 'Legacy IGSN DIF metadata backfill applied.'
            : 'Dry run only; no data was changed and no DataCite sync was queued.');
        $this->table(
            [
                'Scanned', 'Changed', 'Unchanged', 'Manual review', 'Privacy conflicts',
                'Missing DIF', 'Invalid DIF', 'Unknown paths', 'Portal errors',
                'Database errors', 'Cache failures', 'Errors', 'Sync candidates',
            ],
            [[
                $result['scanned'],
                $result['changed'],
                $result['unchanged'],
                $result['manual_review'],
                $result['privacy_conflict'],
                $result['missing_dif'],
                $result['invalid_dif'],
                $result['unknown_paths'],
                $result['portal_errors'],
                $result['database_errors'],
                $result['cache_invalidation_failures'],
                $result['errors'],
                count($result['sync_resource_ids']),
            ]],
        );
        $this->line('Last scanned resource ID: '.($result['last_scanned_resource_id'] ?? 'none'));
        if ($syncRunId !== null) {
            $this->info('DataCite full-metadata sync run: '.$syncRunId);
        }
        if ($result['manual_review'] > 0) {
            $this->warn('Some values were left unchanged and need manual review; inspect the CSV report.');
        }
        if ($result['cache_invalidation_failures'] > 0) {
            $this->warn('Some landing-page caches could not be invalidated; the metadata changes remain applied and eligible IGSNs remain queued for synchronization.');
        }

        $reportFailed = false;
        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            try {
                $this->writeCsv(trim($reportPath), $result['records']);
                $this->info('Backfill report written to '.$reportPath);
            } catch (Throwable $exception) {
                report($exception);
                $reportFailed = true;
                $this->error('Unable to write backfill report: '.$exception->getMessage());
            }
        }

        return $result['errors'] > 0 || $reportFailed ? self::FAILURE : self::SUCCESS;
    }

    private function retrySync(string $syncRunId): int
    {
        if (! Str::isUuid($syncRunId)) {
            $this->error('The sync run ID must be a UUID.');

            return self::INVALID;
        }
        if (! $this->syncDispatcher->retryFailures(ImportProgressService::TYPE_IGSN, $syncRunId)) {
            $this->warn('No retryable DataCite synchronization failures were found.');

            return self::FAILURE;
        }

        $this->info('Failed DataCite synchronizations were queued again.');

        return self::SUCCESS;
    }

    /** @param list<array<string, int|string|null>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $directory = dirname($path);
        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Report directory path is not a directory: '.$directory);
        }
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create report directory: '.$directory);
        }

        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to write report: '.$path);
        }

        $columns = [
            'resource_id', 'doi', 'handle', 'datacenter', 'schema_namespace', 'status',
            'changed_fields', 'existing_values', 'source_values', 'inserted_values',
            'conflicts', 'unknown_paths', 'missing_dif',
            'datacite_sync_status', 'sync_run_id', 'message',
        ];
        try {
            fputcsv($stream, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(static fn (string $column): int|string|null => $row[$column] ?? null, $columns), escape: '');
            }
        } finally {
            fclose($stream);
        }
    }
}
