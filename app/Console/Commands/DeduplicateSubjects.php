<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Subjects\SubjectDuplicateCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Description('Audit and remove exact duplicate subjects within individual resources.')]
#[Signature('subjects:deduplicate
    {--apply : Delete duplicates; without this option the command is a dry run}
    {--after-resource-id=0 : Resume after this resource ID}
    {--limit=0 : Maximum number of resources to scan; zero means all}
    {--chunk=250 : Number of resources fetched per database batch}
    {--doi=* : Restrict the run to one or more DOI resources}
    {--scheme=* : Restrict the run to one or more exact subject schemes}
    {--include-free : Include exact duplicate free-text subjects}
    {--report= : Optional CSV report path}')]
final class DeduplicateSubjects extends Command
{
    public function __construct(private readonly SubjectDuplicateCleanupService $cleanup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->cleanup->run(
            apply: (bool) $this->option('apply'),
            afterResourceId: max(0, (int) $this->option('after-resource-id')),
            limit: max(0, (int) $this->option('limit')),
            chunk: max(1, min(1000, (int) $this->option('chunk'))),
            dois: array_values(array_filter($this->option('doi'), 'is_string')),
            schemes: array_values(array_filter($this->option('scheme'), 'is_string')),
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
