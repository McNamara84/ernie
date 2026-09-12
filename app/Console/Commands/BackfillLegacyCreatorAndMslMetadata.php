<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use App\Services\Legacy\LegacyCreatorAndMslMetadataBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Description('Audit and backfill resource-specific creator names and legacy EPOS WP16 MSL subjects from SUMARIO.')]
#[Signature('resources:backfill-legacy-creator-and-msl-metadata
    {--apply : Persist safe changes; without this option the command is a dry run}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of ERNIE resources; zero means all}
    {--chunk=100 : ERNIE resources per database batch (maximum 1000)}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--legacy-id=* : Restrict linked records to one or more SUMARIO resource IDs}
    {--match-by-doi : Also inspect older resources without a legacy_source_id by matching their DOI uniquely in SUMARIO}
    {--report= : Optional CSV report path}
    {--retry-sync= : Retry failed DataCite synchronization for a prior sync run UUID}')]
final class BackfillLegacyCreatorAndMslMetadata extends Command
{
    public function __construct(
        private readonly LegacyCreatorAndMslMetadataBackfillService $backfill,
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
                chunk: max(1, min(1000, (int) $this->option('chunk'))),
                dois: array_values(array_filter($this->option('doi'), 'is_string')),
                legacyIds: array_values(array_filter(
                    array_map('intval', $this->option('legacy-id')),
                    static fn (int $id): bool => $id > 0,
                )),
                matchByDoi: (bool) $this->option('match-by-doi'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Legacy SUMARIO preflight or backfill failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $syncRunId = null;
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
            foreach ($result['records'] as &$record) {
                if (in_array($record['resource_id'], $result['sync_resource_ids'], true)) {
                    $record['datacite_sync_status'] = config('datacite.test_mode') !== false
                        ? 'skipped_test_mode'
                        : 'queued:'.$syncRunId;
                }
            }
            unset($record);
        }

        $this->info((bool) $this->option('apply')
            ? 'Legacy creator and MSL metadata backfill applied.'
            : 'Dry run only; no data was changed and no DataCite sync was queued.');
        $this->table(
            [
                'Scanned', 'Changed', 'Unchanged', 'Missing legacy', 'Manual review',
                'Concurrent', 'Errors', 'Snapshots', 'Visible names', 'Subjects created',
                'Subjects enriched', 'Subject conflicts', 'Cache failures', 'Sync candidates',
            ],
            [[
                $result['scanned'],
                $result['changed'],
                $result['unchanged'],
                $result['missing_legacy'],
                $result['manual_review'],
                $result['concurrent_changes'],
                $result['errors'],
                $result['creator_snapshots_written'],
                $result['visible_creator_changes'],
                $result['subjects_created'],
                $result['subjects_enriched'],
                $result['subject_conflicts'],
                $result['cache_invalidation_failures'],
                count($result['sync_resource_ids']),
            ]],
        );
        $this->line('Last scanned resource ID: '.($result['last_scanned_resource_id'] ?? 'none'));
        if ($syncRunId !== null) {
            $this->info('DataCite full-metadata sync run: '.$syncRunId);
        }
        if ($result['manual_review'] > 0 || $result['concurrent_changes'] > 0) {
            $this->warn('Some values were left unchanged and need manual review; inspect the CSV report.');
        }
        if ($result['cache_invalidation_failures'] > 0) {
            $this->warn('Some landing-page caches could not be invalidated; metadata changes remain applied.');
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
        if (! $this->syncDispatcher->retryFailures(ImportProgressService::TYPE_RESOURCE, $syncRunId)) {
            $this->warn('No retryable DataCite synchronization failures were found.');

            return self::FAILURE;
        }

        $this->info('Failed DataCite synchronizations were queued again.');

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
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
            'resource_id', 'doi', 'legacy_resource_id', 'match_method', 'status',
            'legacy_creators', 'ernie_creators', 'creator_match_methods',
            'creator_snapshots_written', 'visible_creator_changes', 'legacy_msl_subjects',
            'subjects_created', 'subjects_enriched', 'subject_conflicts',
            'concurrent_change', 'cache_invalidation_failed',
            'datacite_sync_status', 'message',
        ];

        try {
            fputcsv($stream, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    fn (string $column): string|int|null => $this->spreadsheetSafeCell($row[$column] ?? null),
                    $columns,
                ), escape: '');
            }
        } finally {
            fclose($stream);
        }
    }

    private function spreadsheetSafeCell(mixed $value): string|int|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
