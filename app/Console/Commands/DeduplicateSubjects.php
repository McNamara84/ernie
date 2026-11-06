<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Subjects\SubjectDuplicateCleanupService;
use App\Services\Subjects\SubjectEquivalentCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Description('Audit and remove exact or locally verified equivalent subjects within resources.')]
#[Signature('subjects:deduplicate
    {--apply : Delete duplicates; without this option the command is a dry run}
    {--after-resource-id=0 : Resume after this resource ID}
    {--limit=0 : Maximum number of resources to scan; zero means all}
    {--chunk=250 : Number of resources fetched per database batch}
    {--doi=* : Restrict the run to one or more DOI resources}
    {--scheme=* : Restrict the run to one or more exact subject schemes}
    {--include-free : Include exact duplicate free-text subjects}
    {--mode=exact : exact or equivalent}
    {--from-report= : Reviewed equivalent-mode JSON report required for apply or cache recovery}
    {--refresh-caches : Retry cache invalidation from an equivalent report without modifying subjects}
    {--report= : CSV report in exact mode; new JSON report or JSONL apply journal in equivalent mode}')]
final class DeduplicateSubjects extends Command
{
    public function __construct(private readonly SubjectDuplicateCleanupService $cleanup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('mode') === 'equivalent') {
            return $this->equivalent();
        }
        if ($this->option('mode') !== 'exact' || $this->option('from-report') || $this->option('refresh-caches')) {
            $this->error('Use --mode=equivalent for reviewed reports and cache recovery.');

            return Command::FAILURE;
        }
        $result = $this->cleanup->run(
            apply: (bool) $this->option('apply'),
            afterResourceId: max(0, (int) $this->option('after-resource-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(1000, (int) $this->option('chunk'))),
            dois: array_values(array_filter((array) $this->option('doi'), 'is_string')),
            schemes: array_values(array_filter((array) $this->option('scheme'), 'is_string')),
            includeFree: (bool) $this->option('include-free'),
        );

        $this->info($this->option('apply')
            ? 'Exact subject duplicate cleanup applied.'
            : 'Dry run only; no data was changed.');
        $this->table(
            ['Resources', 'Subjects', 'Groups', 'Duplicate rows', 'Assistant rows', 'Unchanged', 'Errors'],
            [[
                $result['resources_scanned'],
                $result['subjects_scanned'],
                $result['duplicate_groups'],
                $result['duplicate_subjects'],
                $result['assistant_rows'],
                $result['unchanged_resources'],
                $result['errors'],
            ]],
        );

        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $this->writeCsv(trim($reportPath), $result['records']);
            $this->info('Subject duplicate report written to '.$reportPath);
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function equivalent(): int
    {
        $path = $this->option('report');
        $input = $this->option('from-report');
        $apply = (bool) $this->option('apply');
        $refresh = (bool) $this->option('refresh-caches');
        if (! is_string($path) || trim($path) === '' || (($apply || $refresh) && (! is_string($input) || trim($input) === ''))
            || ($apply && $refresh) || (! $apply && ! $refresh && $input)) {
            $this->error('Equivalent mode requires --report; apply/cache recovery also requires --from-report. Choose apply or cache recovery.');

            return Command::FAILURE;
        }
        if (($apply || $refresh) && ($this->option('doi') !== [] || $this->option('scheme') !== [] || $this->option('include-free')
            || (int) $this->option('after-resource-id') !== 0 || (int) $this->option('limit') !== 0)) {
            $this->error('Apply uses the reviewed report selection. Do not supply new filters.');

            return Command::FAILURE;
        }
        $stream = null;
        try {
            $service = app(SubjectEquivalentCleanupService::class);
            $reviewed = null;
            if ($apply || $refresh) {
                $contents = is_string($input) ? file_get_contents($input) : false;
                $reviewed = is_string($contents) ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! is_array($reviewed)) {
                    throw new RuntimeException('Unable to read the reviewed JSON report.');
                }
            }
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create report directory.');
            }
            // Never overwrite a reviewed snapshot, previous journal, or operator-owned file.
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Report must be a new writable file.');
            }
            $write = static function (array $record) use ($stream): void {
                $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($stream, $json) !== strlen($json) || ! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Unable to persist the subject cleanup report.');
                }
            };
            if ($reviewed !== null) {
                $write(['status' => 'started', 'report' => $reviewed]);
                $results = $service->apply($reviewed, $write, $refresh);
                $this->table(['Resource', 'Status', 'Message'], array_map(array_values(...), $results));

                return array_any($results, static fn (array $row): bool => in_array($row['status'], ['error', 'cache_error'], true))
                    ? Command::FAILURE : Command::SUCCESS;
            }
            $report = $service->audit(
                dois: array_values(array_filter((array) $this->option('doi'), 'is_string')),
                schemes: array_values(array_filter((array) $this->option('scheme'), 'is_string')),
                includeFree: (bool) $this->option('include-free'),
                after: max(0, (int) $this->option('after-resource-id')),
                limit: max(0, (int) $this->option('limit')),
                chunk: max(1, min(1000, (int) $this->option('chunk'))),
            );
            $write($report);
            $this->info('Dry run only; no data was changed. Review the JSON report before applying.');
            $this->info('Scanned '.$report['resources_scanned'].' resources; resume after resource ID '.$report['last_resource_id'].'.');
            $this->table(['Resource', 'DOI', 'Safe groups', 'Unresolved groups'], array_map(static fn (array $row): array => [
                $row['resource_id'], $row['doi'], count($row['analysis']['groups']), count($row['analysis']['diagnostics']),
            ], $report['resources']));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  list<array{
     *     resource_id: int,
     *     doi: string,
     *     scheme: string,
     *     survivor_id: int,
     *     duplicate_ids: string,
     *     group_size: int,
     *     status: string,
     *     message: string
     * }>  $rows
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
                'scheme',
                'survivor_id',
                'duplicate_ids',
                'group_size',
                'status',
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
