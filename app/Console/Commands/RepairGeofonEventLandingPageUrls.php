<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DataCiteMemberApiClient;
use App\Services\GeofonEventLandingPageUrlRepairService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Description('Audit and repair legacy GEOFON seismic-event landing-page URLs in ERNIE and DataCite.')]
#[Signature('resources:repair-geofon-event-landing-page-urls
    {--apply : Update eligible DataCite and ERNIE URLs; without this option the command is a dry run}
    {--force-production : Required with --apply when the production DataCite endpoint is active}
    {--doi=* : Restrict the run to one or more resource DOIs}
    {--after-id=0 : Resume after this ERNIE resource ID}
    {--limit=0 : Maximum number of eligible resources; zero means all}
    {--report= : Optional CSV result report path}')]
final class RepairGeofonEventLandingPageUrls extends Command
{
    /** @var list<string> */
    private const array REPORT_COLUMNS = [
        'resource_id',
        'doi',
        'datacenter',
        'event_id',
        'local_before_url',
        'datacite_before_url',
        'target_url',
        'local_status',
        'datacite_status',
        'datacite_state',
        'target_http_status',
        'target_effective_url',
        'update_http_status',
        'overall_status',
        'snapshot_path',
        'message',
    ];

    public function __construct(
        private readonly GeofonEventLandingPageUrlRepairService $repair,
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

            $this->table(['Mode', 'Endpoint', 'Repository client', 'Datacenter', 'Operation'], [[
                $this->client->isTestMode() ? 'test' : 'production',
                $this->client->endpoint(),
                $this->client->repositoryClientId(),
                LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
                $apply ? 'APPLY' : 'DRY RUN',
            ]]);

            $result = $this->repair->run(
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
            $this->error('GEOFON event URL repair failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($apply
            ? 'GEOFON event landing-page URL repair finished.'
            : 'Dry run only; no ERNIE or DataCite records were changed.');
        $this->table(
            [
                'Scanned', 'Candidates', 'Current', 'Would update', 'Updated',
                'Local updates', 'DataCite updates', 'Wrong datacenter',
                'Manual review', 'Unreachable', 'Concurrent', 'Skipped', 'Errors',
            ],
            [[
                $result['resources_scanned'],
                $result['candidates'],
                $result['already_current'],
                $result['would_update'],
                $result['updated'],
                $result['local_updated'],
                $result['datacite_updated'],
                $result['wrong_datacenter'],
                $result['manual_review'],
                $result['target_unreachable'],
                $result['concurrent_changes'],
                $result['skipped'],
                $result['errors'],
            ]],
        );
        $this->line('Last processed resource ID: '.($result['last_resource_id'] ?? 'none'));
        if (is_string($result['snapshot_directory']) && $result['snapshot_directory'] !== '') {
            $this->info('Private pre-update snapshots: storage/app/private/'.$result['snapshot_directory']);
        }

        if ($result['manual_review'] > 0) {
            $this->warn('Some records need manual review and were not changed; inspect the CSV report.');
        }
        if ($result['target_unreachable'] > 0) {
            $this->warn('Some canonical GEOFON event pages were not reachable and were not changed.');
        }

        $reportFailed = false;
        $reportPath = $this->option('report');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            try {
                $this->writeCsv(trim($reportPath), $result['records']);
                $this->info('GEOFON event URL report written to '.trim($reportPath));
            } catch (Throwable $exception) {
                report($exception);
                $reportFailed = true;
                $this->error('Unable to write GEOFON event URL report: '.$exception->getMessage());
            }
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
            $written = fputcsv($stream, self::REPORT_COLUMNS, escape: '');
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write report header: '.$path);
            }

            foreach ($rows as $index => $row) {
                $written = fputcsv($stream, array_map(
                    fn (string $column): int|string|null => $this->spreadsheetSafeCell($row[$column] ?? null),
                    self::REPORT_COLUMNS,
                ), escape: '');
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write report row '.($index + 1).': '.$path);
                }
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
        if ($value === '' || preg_match('/\A\s*(?:[=+\-@]|\t|\r|\n)/u', $value) !== 1) {
            return $value;
        }

        return "'".$value;
    }
}
