<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteSchemaUpgradeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Description('Audit and upgrade imported DataCite resource records to Kernel 4.')]
#[Signature('resources:upgrade-datacite-schema
    {--apply : Send eligible schema upgrades to DataCite; without this option the command is a dry run}
    {--force-production : Required with --apply when the production DataCite endpoint is active}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of eligible resources; zero means all}
    {--report= : Optional CSV result report path}')]
final class UpgradeDataCiteSchemas extends Command
{
    /** @var list<string> */
    private const array REPORT_COLUMNS = [
        'resource_id',
        'doi',
        'datacite_client',
        'state',
        'source_schema',
        'target_schema',
        'status',
        'resource_type_general',
        'http_status',
        'attempts',
        'verified_at',
        'message',
    ];

    public function __construct(
        private readonly DataCiteSchemaUpgradeService $upgrade,
        private readonly DataCiteMemberApiClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->client->isTestMode() && ! (bool) $this->option('force-production')) {
            $this->error('Production writes require both --apply and --force-production.');

            return self::INVALID;
        }

        try {
            $afterId = $this->integerOption('after-id');
            $limit = $this->integerOption('limit');
            $dois = array_values(array_filter(
                $this->option('doi'),
                static fn (mixed $doi): bool => is_string($doi),
            ));

            $this->table(['Mode', 'Endpoint', 'Repository client', 'Operation'], [[
                $this->client->isTestMode() ? 'test' : 'production',
                $this->client->endpoint(),
                $this->client->repositoryClientId(),
                $apply ? 'APPLY' : 'DRY RUN',
            ]]);

            $result = $this->upgrade->run(
                apply: $apply,
                afterId: $afterId,
                limit: $limit,
                dois: $dois,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('DataCite schema audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($apply
            ? 'DataCite Kernel 4 upgrade run finished.'
            : 'Dry run only; no DataCite records were changed.');
        $this->table(
            [
                'Remote', 'Selected', 'Candidates', 'Current', 'Would update',
                'Updated', 'Manual review', 'Not imported', 'Excluded', 'Skipped', 'Errors',
            ],
            [[
                $result['remote_scanned'],
                $result['selected'],
                $result['candidates'],
                $result['already_current'],
                $result['would_update'],
                $result['updated'],
                $result['manual_review'],
                $result['not_imported'],
                $result['excluded'],
                $result['skipped'],
                $result['errors'],
            ]],
        );
        $this->line('Last processed resource ID: '.($result['last_resource_id'] ?? 'none'));
        if (is_string($result['snapshot_directory']) && $result['snapshot_directory'] !== '') {
            $this->info('Private pre-upgrade snapshots: storage/app/private/'.$result['snapshot_directory']);
        }

        $reportFailed = false;
        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            try {
                $this->writeCsv(trim($reportPath), $result['records']);
                $this->info('Schema-upgrade report written to '.trim($reportPath));
            } catch (Throwable $exception) {
                report($exception);
                $reportFailed = true;
                $this->error('Unable to write schema-upgrade report: '.$exception->getMessage());
            }
        }

        if ($result['manual_review'] > 0) {
            $this->warn('Some DataCite records need manual review; inspect the CSV report before retrying them.');
        }

        return $result['errors'] > 0 || $result['manual_review'] > 0 || $reportFailed
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function integerOption(string $name): int
    {
        $value = $this->option($name);
        if (! is_int($value) && ! is_string($value)) {
            throw new \InvalidArgumentException("The --{$name} option must be a non-negative integer.");
        }

        $normalized = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException("The --{$name} option must be a non-negative integer.");
        }

        $parsed = filter_var($normalized, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($parsed === false) {
            throw new \InvalidArgumentException("The --{$name} option is outside the supported integer range.");
        }

        return $parsed;
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

        try {
            fputcsv($stream, self::REPORT_COLUMNS, escape: '');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    fn (string $column): int|string|null => $this->spreadsheetSafeCell($row[$column] ?? null),
                    self::REPORT_COLUMNS,
                ), escape: '');
            }
        } finally {
            fclose($stream);
        }
    }

    private function spreadsheetSafeCell(mixed $value): int|string|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        $value = is_string($value) ? $value : (string) $value;
        if ($value === '' || ! str_contains('=+-@', $value[0])) {
            return $value;
        }

        return "'".$value;
    }
}
