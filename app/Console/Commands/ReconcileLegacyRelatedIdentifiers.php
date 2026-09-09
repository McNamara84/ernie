<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DoiSuggestionService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use App\Services\Legacy\LegacyRelatedIdentifierReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Description('Audit and add related identifiers missing from migrated SUMARIO resources.')]
#[Signature('resources:reconcile-legacy-related-identifiers
    {--apply : Persist missing related identifiers; without this option the command is a dry run}
    {--sync-datacite : Queue full-metadata DataCite synchronization for changed resources (requires --apply)}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of ERNIE resources; zero means all}
    {--chunk=100 : ERNIE resources per database batch (maximum 1000)}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--report= : Optional CSV report path}')]
final class ReconcileLegacyRelatedIdentifiers extends Command
{
    public function __construct(
        private readonly LegacyRelatedIdentifierReconciliationService $reconciliation,
        private readonly ImportedResourceDataCiteSyncDispatcherService $syncDispatcher,
        private readonly ImportProgressService $progressService,
        private readonly DoiSuggestionService $doiSuggestionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $syncDataCite = (bool) $this->option('sync-datacite');

        if ($syncDataCite && ! $apply) {
            $this->error('--sync-datacite requires --apply. No data was changed.');

            return Command::INVALID;
        }

        $doiOptions = array_values(array_filter($this->option('doi'), 'is_string'));
        $invalidDois = array_values(array_filter(
            $doiOptions,
            fn (string $doi): bool => ! $this->doiSuggestionService->isValidDoiFormat($doi),
        ));
        if ($invalidDois !== []) {
            $this->error('Invalid DOI filter(s): '.implode(', ', $invalidDois).'. No data was changed.');

            return Command::INVALID;
        }

        $result = $this->reconciliation->run(
            apply: $apply,
            afterId: max(0, (int) $this->option('after-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(1000, (int) $this->option('chunk'))),
            dois: $doiOptions,
        );

        $this->info($apply
            ? 'Legacy related identifier reconciliation applied.'
            : 'Dry run only; no data was changed.');
        $this->table(
            ['Scanned', 'Legacy', 'Changed', 'Unchanged', 'Missing legacy', 'Relations found', $apply ? 'Added' : 'Would add', 'Invalid', 'Duplicates', 'Cache failures', 'Errors', 'Sync candidates'],
            [[
                $result['resources_scanned'],
                $result['legacy_resources'],
                $result['changed'],
                $result['unchanged'],
                $result['missing_legacy'],
                $result['relations_found'],
                $result['relations_added'],
                $result['invalid_relations'],
                $result['duplicate_legacy_relations'],
                $result['cache_invalidation_failures'],
                $result['errors'],
                count($result['sync_resource_ids']),
            ]],
        );
        $this->line('Last scanned resource ID: '.($result['last_scanned_resource_id'] ?? 'none'));

        if ($result['invalid_relations'] > 0 || $result['errors'] > 0) {
            $this->warn('Some legacy records were skipped; inspect the report before applying or synchronizing.');
        }
        if ($result['cache_invalidation_failures'] > 0) {
            $this->warn('Some changed landing-page caches could not be invalidated.');
        }

        if ($syncDataCite && $result['sync_resource_ids'] !== []) {
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
        } elseif ($apply && ! $syncDataCite && $result['sync_resource_ids'] !== []) {
            $this->comment('DataCite was not synchronized. Use the established metadata-update workflow for these reported resource IDs.');
        }

        $reportFailed = false;
        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            try {
                $this->writeCsv(trim($reportPath), $result['records']);
                $this->info('Reconciliation report written to '.$reportPath);
            } catch (Throwable $exception) {
                report($exception);
                $reportFailed = true;
                $this->error('Unable to write reconciliation report: '.$exception->getMessage());
            }
        }

        return $result['errors'] > 0 || $reportFailed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<array{resource_id: int, doi: string, legacy_resource_id: int|null, status: string, legacy_relations: int, missing_relations: int, invalid_relations: int, duplicate_legacy_relations: int, message: string}>  $rows
     */
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

        try {
            fputcsv($stream, [
                'resource_id',
                'doi',
                'legacy_resource_id',
                'status',
                'legacy_relations',
                'missing_relations',
                'invalid_relations',
                'duplicate_legacy_relations',
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
