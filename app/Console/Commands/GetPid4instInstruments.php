<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pid4instVocabularyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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
#[Description('Fetch all instruments from the b2inst API and store locally')]
#[Signature('get-pid4inst-instruments')]
class GetPid4instInstruments extends Command
{
    public function handle(Pid4instVocabularyService $vocabulary): int
    {
        try {
            return $this->download($vocabulary);
        } catch (\Throwable $exception) {
            $this->error('Failed to update instrument registry: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function download(Pid4instVocabularyService $vocabulary): int
    {
        /** @var string $host */
        $host = rtrim((string) config('b2inst.host'), '/');
        /** @var int $pageSize */
        $pageSize = config('b2inst.page_size', 100);
        $page = 1;
        $expectedTotal = null;
        $seenIds = [];

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
                ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch page {$page}: HTTP {$response->status()}");

                return self::FAILURE;
            }

            $data = $response->json();
            $hits = $data['hits']['hits'] ?? null;
            $total = $data['hits']['total'] ?? null;
            if (! is_array($hits) || ! array_is_list($hits) || ! is_int($total) || $total < 0
                || ($expectedTotal !== null && $expectedTotal !== $total)) {
                throw new \RuntimeException('Invalid or changing instrument result set.');
            }
            $expectedTotal = $total;

            foreach ($hits as $hit) {
                if (! is_array($hit) || ! is_string($hit['id'] ?? null) || $hit['id'] === ''
                    || isset($seenIds[$hit['id']]) || ! is_array($hit['metadata'] ?? null)
                    || ! is_string($hit['metadata']['Name'] ?? null)
                    || ! is_string($hit['metadata']['Identifier']['identifierValue'] ?? null)) {
                    throw new \RuntimeException('Invalid or repeated instrument record.');
                }
                $seenIds[$hit['id']] = true;
                $allInstruments[] = $this->transformRecord($hit);
            }

            $this->info("Page {$page}: fetched ".count($hits).' records (total so far: '.count($allInstruments)."/{$total})");
            $page++;
        } while (count($allInstruments) < $total && count($hits) > 0);
        if (count($allInstruments) !== $total) {
            throw new \RuntimeException('Incomplete instrument result set.');
        }

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

        $vocabulary->replace($json);

        $this->info('Successfully stored '.count($allInstruments).' instruments.');

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
        $lookupIdentifiers = [[
            'value' => (string) ($identifier['identifierValue'] ?? ''),
            'type' => $this->normalizePidType((string) ($identifier['identifierType'] ?? 'Handle')),
            'source' => 'metadata',
        ]];
        $providerPid = $hit['pids']['epic']['identifier'] ?? null;
        if (is_string($providerPid) && trim($providerPid) !== '') {
            $lookupIdentifiers[] = ['value' => $providerPid, 'type' => 'Handle', 'source' => 'epic'];
        }

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
            'lookupIdentifiers' => $lookupIdentifiers,
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
