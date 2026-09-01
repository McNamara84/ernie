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
            $afterId = $this->integerOption('after-id', minimum: 0);
            $limit = $this->integerOption('limit', minimum: 0);
            $chunk = $this->integerOption('chunk', minimum: 1, maximum: 100);
            $result = $this->backfill->run(
                apply: (bool) $this->option('apply'),
                afterId: $afterId,
                limit: $limit,
                chunk: $chunk,
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
                fputcsv($stream, array_map(
                    fn (string $column): int|string|null => $this->spreadsheetSafeCell($row[$column] ?? null),
                    $columns,
                ), escape: '');
            }
        } finally {
            fclose($stream);
        }
    }

    private function integerOption(string $name, int $minimum, ?int $maximum = null): int
    {
        $value = $this->option($name);
        if (! is_int($value) && ! is_string($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer.', $name));
        }

        $value = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a non-negative integer.', $name));
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => array_filter([
                'min_range' => $minimum,
                'max_range' => $maximum,
            ], static fn (?int $range): bool => $range !== null),
        ]);
        if ($parsed === false) {
            $range = $maximum === null ? sprintf('%d or greater', $minimum) : sprintf('%d to %d', $minimum, $maximum);

            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %s.', $name, $range));
        }

        return $parsed;
    }

    private function spreadsheetSafeCell(int|string|null $value): int|string|null
    {
        if (! is_string($value) || $value === '' || ! str_contains('=+-@', $value[0])) {
            return $value;
        }

        return "'".$value;
    }
}
