<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch all public instruments from the b2inst API and store locally as JSON.
 *
 * Downloads instrument records from the EUDAT b2inst registry
 * (https://b2inst.gwdg.de) with PID4INST metadata and stores them
 * in a local JSON file for use in the ERNIE editor.
 *
 * @see https://docs.eudat.eu/b2inst/httpapi/
 * @see https://docs.pidinst.org/en/latest/white-paper/linking-datasets.html
 */
class GetPid4instInstruments extends Command
{
    protected $signature = 'get-pid4inst-instruments';

    protected $description = 'Fetch all instruments from the b2inst API and store locally';

    public function handle(): int
    {
        /** @var string $host */
        $host = rtrim((string) config('b2inst.host'), '/');
        /** @var int $pageSize */
        $pageSize = config('b2inst.page_size', 100);
        $page = 1;

        /** @var list<array{id: string, pid: string, pidType: string, name: string, description: string, landingPage: string, owners: list<string>, manufacturers: list<string>, model: string|null, instrumentTypes: list<string>, measuredVariables: list<string>}> $allInstruments */
        $allInstruments = [];

        $this->info('Fetching instruments from b2inst API...');
        $this->info("Host: {$host}");

        do {
            $response = Http::timeout(60)
                ->accept('application/json')
                ->get("{$host}/api/records", [
                    'size' => $pageSize,
                    'page' => $page,
                    'sort' => 'mostrecent',
                ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch page {$page}: HTTP {$response->status()}");

                return self::FAILURE;
            }

            /** @var array{hits: array{hits: list<array<string, mixed>>, total: int}, links: array<string, string>} $data */
            $data = $response->json();
            /** @var list<array<string, mixed>> $hits */
            $hits = $data['hits']['hits'];
            /** @var int $total */
            $total = $data['hits']['total'];

            foreach ($hits as $hit) {
                $allInstruments[] = $this->transformRecord($hit);
            }

            $this->info("Page {$page}: fetched " . count($hits) . ' records (total so far: ' . count($allInstruments) . "/{$total})");
            $page++;
        } while (count($allInstruments) < $total && count($hits) > 0);

        // Store as JSON
        $json = json_encode([
            'lastUpdated' => now()->toIso8601String(),
            'total' => count($allInstruments),
            'data' => $allInstruments,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode instruments as JSON.');

            return self::FAILURE;
        }

        Storage::put('pid4inst-instruments.json', $json);

        // Clear vocabulary cache
        Artisan::call('cache:clear-app', ['category' => 'vocabularies']);

        $this->info("Successfully stored " . count($allInstruments) . ' instruments.');

        return self::SUCCESS;
    }

    /**
     * Transform a b2inst API record into a normalized instrument structure.
     *
     * @param  array<string, mixed>  $hit  Raw b2inst API record
     * @return array{id: string, pid: string, pidType: string, name: string, description: string, landingPage: string, owners: list<string>, manufacturers: list<string>, model: string|null, instrumentTypes: list<string>, measuredVariables: list<string>}
     */
    private function transformRecord(array $hit): array
    {
        /** @var array<string, mixed> $meta */
        $meta = $hit['metadata'] ?? [];

        /** @var array{identifierValue?: string, identifierType?: string} $identifier */
        $identifier = $meta['Identifier'] ?? [];

        /** @var list<array{ownerName?: string}> $owners */
        $owners = $meta['Owner'] ?? [];

        /** @var list<array{manufacturerName?: string}> $manufacturers */
        $manufacturers = $meta['Manufacturer'] ?? [];

        /** @var array{modelName?: string}|null $model */
        $model = $meta['Model'] ?? null;

        /** @var list<array{instrumentTypeName?: string}> $instrumentTypes */
        $instrumentTypes = $meta['InstrumentType'] ?? [];

        /** @var list<string> $measuredVariables */
        $measuredVariables = $meta['MeasuredVariable'] ?? [];

        return [
            'id' => (string) ($hit['id'] ?? ''),
            'pid' => (string) ($identifier['identifierValue'] ?? ''),
            'pidType' => $this->normalizePidType((string) ($identifier['identifierType'] ?? 'Handle')),
            'name' => (string) ($meta['Name'] ?? ''),
            'description' => (string) ($meta['Description'] ?? ''),
            'landingPage' => (string) ($meta['LandingPage'] ?? ''),
            'owners' => array_map(
                fn (array $o): string => (string) ($o['ownerName'] ?? ''),
                $owners
            ),
            'manufacturers' => array_map(
                fn (array $m): string => (string) ($m['manufacturerName'] ?? ''),
                $manufacturers
            ),
            'model' => $model !== null ? ($model['modelName'] ?? null) : null,
            'instrumentTypes' => array_map(
                fn (array $t): string => (string) ($t['instrumentTypeName'] ?? ''),
                $instrumentTypes
            ),
            'measuredVariables' => $measuredVariables,
        ];
    }

    /**
     * Normalize a PID identifier type from the b2inst API to an accepted value.
     *
     * The backend request validation only accepts 'Handle', 'DOI', or 'URL'.
     * This maps common variants and defaults unknown types to 'Handle'.
     */
    private function normalizePidType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'handle' => 'Handle',
            'doi' => 'DOI',
            'url' => 'URL',
            default => 'Handle',
        };
    }
}
