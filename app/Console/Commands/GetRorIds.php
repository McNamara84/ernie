<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

#[Description('Fetch the latest ROR affiliation identifiers and cache them as JSON.')]
#[Signature('get-ror-ids {--output= : Override the output file path}')]
class GetRorIds extends Command
{
    private const METADATA_URL = 'https://zenodo.org/api/records/';

    private const COMMUNITY = 'ror-data';

    private const OUTPUT_RELATIVE_PATH = 'ror/ror-affiliations.json';

    private const FUNDREF_INDEX_RELATIVE_PATH = 'ror/ror-fundref-index.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for processing large ROR dump
        ini_set('memory_limit', '2G');

        $this->info('Fetching latest ROR data dump metadata…');

        $metadataResponse = Http::retry(3, 500, throw: false)
            ->acceptJson()
            ->get(self::METADATA_URL, [
                'communities' => self::COMMUNITY,
                'sort' => 'mostrecent',
                'size' => 1,
            ]);

        if ($metadataResponse->failed()) {
            $this->error(sprintf('Failed to fetch ROR metadata (HTTP %s).', $metadataResponse->status()));

            return self::FAILURE;
        }

        $record = Arr::get($metadataResponse->json(), 'hits.hits.0');

        if (! $record || ! is_array($record)) {
            $this->error('No ROR data dump records were returned.');

            return self::FAILURE;
        }

        $files = Arr::get($record, 'files', []);

        if (! is_array($files)) {
            $files = [];
        }

        $dataFile = null;

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $key = Arr::get($file, 'key');

            if (is_string($key) && Str::endsWith($key, ['.zip', '.jsonl.gz', '.json.gz'])) {
                $dataFile = $file;

                break;
            }
        }

        if (! $dataFile) {
            $this->error('Unable to locate a data dump within the ROR record.');

            return self::FAILURE;
        }

        $downloadUrl = Arr::get($dataFile, 'links.self');

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            $this->error('The ROR data dump is missing a download URL.');

            return self::FAILURE;
        }

        $this->info('Downloading latest ROR data dump…');

        $downloadResponse = Http::retry(3, 500, throw: false)
            ->withOptions(['stream' => false])
            ->get($downloadUrl);

        if ($downloadResponse->failed()) {
            $this->error(sprintf('Failed to download ROR data dump (HTTP %s).', $downloadResponse->status()));

            return self::FAILURE;
        }

        $temporaryPath = (string) tempnam(sys_get_temp_dir(), 'ror-data-');
        File::put($temporaryPath, $downloadResponse->body());

        $outputPath = $this->option('output');
        $targetPath = is_string($outputPath) && $outputPath !== ''
            ? $outputPath
            : storage_path('app/private/'.self::OUTPUT_RELATIVE_PATH);
        $fundrefIndexPath = $this->fundrefIndexTargetPath($targetPath);

        $directory = dirname($targetPath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0o755, true);
        }

        $fundrefDirectory = dirname($fundrefIndexPath);

        if (! File::exists($fundrefDirectory)) {
            File::makeDirectory($fundrefDirectory, 0o755, true);
        }

        try {
            $fileKey = Arr::get($dataFile, 'key', '');
            $isZip = is_string($fileKey) && Str::endsWith($fileKey, '.zip');

            if ($isZip) {
                $saved = $this->processZipDump($temporaryPath, $targetPath);
            } else {
                $saved = $this->convertDumpToSuggestions($temporaryPath, $targetPath);
            }

            $fundrefSaved = $this->buildFundrefIndex($temporaryPath, $isZip, $fundrefIndexPath);
        } catch (Throwable $exception) {
            File::delete($temporaryPath);
            $this->error(sprintf('Failed to process ROR data dump: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        File::delete($temporaryPath);

        if ($saved === 0) {
            $this->warn('No ROR affiliations were written.');

            return self::FAILURE;
        }

        // Invalidate ROR caches after successful update
        $this->call('cache:clear-app', ['category' => 'ror']);

        $this->info(sprintf('Saved %d ROR affiliation entries to %s', $saved, $targetPath));
        $this->info(sprintf('Saved %d ROR FundRef candidate entries to %s', $fundrefSaved, $fundrefIndexPath));

        return self::SUCCESS;
    }

    private function processZipDump(string $zipPath, string $targetPath): int
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive.');
        }

        // Find the JSON file in the ZIP (prefer schema v1 for smaller size)
        $jsonFile = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (is_string($filename) && Str::endsWith($filename, '.json')) {
                // Prefer schema v1 files (without _schema_v2 suffix) for smaller footprint
                if (! Str::contains($filename, 'schema_v2')) {
                    $jsonFile = $filename;

                    break;
                }

                // Fallback to v2 files
                if ($jsonFile === null) {
                    $jsonFile = $filename;
                }
            }
        }

        if ($jsonFile === null) {
            $zip->close();

            throw new \RuntimeException('No JSON file found in the ROR data archive.');
        }

        $this->info(sprintf('Processing %s from archive…', $jsonFile));

        // Extract JSON content
        $jsonContent = $zip->getFromName($jsonFile);
        $zip->close();

        if ($jsonContent === false) {
            throw new \RuntimeException(sprintf('Failed to extract %s from archive.', $jsonFile));
        }

        // Create temporary file for JSON content
        $tempJsonPath = (string) tempnam(sys_get_temp_dir(), 'ror-json-');
        File::put($tempJsonPath, $jsonContent);

        try {
            $count = $this->convertJsonToSuggestions($tempJsonPath, $targetPath);
        } finally {
            File::delete($tempJsonPath);
        }

        return $count;
    }

    private function convertJsonToSuggestions(string $sourcePath, string $targetPath): int
    {
        $this->info('Loading JSON data into memory...');
        $jsonContent = file_get_contents($sourcePath);

        if ($jsonContent === false) {
            throw new \RuntimeException("Failed to read file: {$sourcePath}");
        }

        $this->info('Parsing JSON...');
        $organizations = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

        // Free memory
        unset($jsonContent);

        if (! is_array($organizations)) {
            throw new \RuntimeException('Invalid JSON structure in ROR data file.');
        }

        $this->info(sprintf('Found %d organizations, processing...', count($organizations)));

        $outputHandle = fopen($targetPath, 'wb');

        if ($outputHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open output path [%s] for writing.', $targetPath));
        }

        $timestamp = Carbon::now()->toIso8601String();
        fwrite($outputHandle, '{"lastUpdated":'.json_encode($timestamp, JSON_THROW_ON_ERROR).',"data":[');

        $first = true;
        $count = 0;

        foreach ($organizations as $decoded) {
            if (! is_array($decoded)) {
                continue;
            }

            $entry = $this->processOrganization($decoded);

            if ($entry !== null) {
                $encoded = json_encode($entry, JSON_THROW_ON_ERROR);

                if (! $first) {
                    fwrite($outputHandle, ',');
                } else {
                    $first = false;
                }

                fwrite($outputHandle, $encoded);
                $count++;

                if ($count % 10000 === 0) {
                    $this->info(sprintf('Processed %d organizations...', $count));
                }
            }
        }

        fwrite($outputHandle, '],"total":'.$count.'}');
        fclose($outputHandle);

        return $count;
    }

    /**
     * Process a single ROR organization record.
     *
     * @param  array<string, mixed>  $decoded  The organization data array
     * @return array{prefLabel: string, rorId: string, otherLabel: array<int, string>}|null
     */
    private function processOrganization(array $decoded): ?array
    {
        // Try schema v2 structure first
        $identifier = Arr::get($decoded, 'id');

        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        // Get the display name from the names array (schema v2)
        $names = Arr::get($decoded, 'names', []);
        $preferredName = null;

        if (is_array($names)) {
            foreach ($names as $nameEntry) {
                if (! is_array($nameEntry)) {
                    continue;
                }

                $types = Arr::get($nameEntry, 'types', []);

                if (is_array($types) && in_array('ror_display', $types, true)) {
                    $preferredName = Arr::get($nameEntry, 'value');

                    break;
                }
            }

            // Fallback to label type
            if ($preferredName === null) {
                foreach ($names as $nameEntry) {
                    if (! is_array($nameEntry)) {
                        continue;
                    }

                    $types = Arr::get($nameEntry, 'types', []);

                    if (is_array($types) && in_array('label', $types, true)) {
                        $preferredName = Arr::get($nameEntry, 'value');

                        break;
                    }
                }
            }
        }

        // Fallback to v1 structure
        if ($preferredName === null || $preferredName === '') {
            $preferredName = Arr::get($decoded, 'name');
        }

        if (! is_string($preferredName) || $preferredName === '') {
            return null;
        }

        // Collect all search terms from names
        $searchTerms = [$preferredName];

        if (is_array($names)) {
            foreach ($names as $nameEntry) {
                if (! is_array($nameEntry)) {
                    continue;
                }

                $value = Arr::get($nameEntry, 'value');

                if (is_string($value) && $value !== '' && $value !== $preferredName) {
                    $searchTerms[] = $value;
                }
            }
        }

        // Fallback to v1 aliases, acronyms, labels
        $aliases = Arr::get($decoded, 'aliases', []);

        if (is_array($aliases)) {
            $aliases = array_values(array_filter(
                array_map('strval', $aliases),
                fn (string $alias) => $alias !== ''
            ));
            $searchTerms = array_merge($searchTerms, $aliases);
        }

        $acronyms = Arr::get($decoded, 'acronyms', []);

        if (is_array($acronyms)) {
            $acronyms = array_values(array_filter(
                array_map('strval', $acronyms),
                fn (string $acronym) => $acronym !== ''
            ));
            $searchTerms = array_merge($searchTerms, $acronyms);
        }

        $rawLabels = Arr::get($decoded, 'labels', []);

        if (is_array($rawLabels)) {
            foreach ($rawLabels as $label) {
                if (! is_array($label)) {
                    continue;
                }

                $labelValue = Arr::get($label, 'label');

                if (is_string($labelValue) && $labelValue !== '') {
                    $searchTerms[] = $labelValue;
                }
            }
        }

        $searchTerms = array_values(array_unique($searchTerms));

        return [
            'prefLabel' => $preferredName,
            'rorId' => $identifier,
            'otherLabel' => $searchTerms,
        ];
    }

    private function convertDumpToSuggestions(string $sourcePath, string $targetPath): int
    {
        $resource = gzopen($sourcePath, 'rb');

        if ($resource === false) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive.');
        }

        $outputHandle = fopen($targetPath, 'wb');

        if ($outputHandle === false) {
            gzclose($resource);

            throw new \RuntimeException(sprintf('Unable to open output path [%s] for writing.', $targetPath));
        }

        $timestamp = Carbon::now()->toIso8601String();
        fwrite($outputHandle, '{"lastUpdated":'.json_encode($timestamp, JSON_THROW_ON_ERROR).',"data":[');

        $first = true;
        $count = 0;

        while (! gzeof($resource)) {
            $line = gzgets($resource);

            if ($line === false) {
                break;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->warn(sprintf('Skipping malformed JSON line: %s', $exception->getMessage()));

                continue;
            }

            $preferredName = Arr::get($decoded, 'name');
            $identifier = Arr::get($decoded, 'id');

            if (! is_string($preferredName) || ! is_string($identifier) || $preferredName === '' || $identifier === '') {
                continue;
            }

            $aliases = Arr::get($decoded, 'aliases', []);

            if (! is_array($aliases)) {
                $aliases = [];
            }

            $aliases = array_values(array_filter(
                array_map('strval', $aliases),
                fn (string $alias) => $alias !== ''
            ));

            $acronyms = Arr::get($decoded, 'acronyms', []);

            if (! is_array($acronyms)) {
                $acronyms = [];
            }

            $acronyms = array_values(array_filter(
                array_map('strval', $acronyms),
                fn (string $acronym) => $acronym !== ''
            ));

            $rawLabels = Arr::get($decoded, 'labels', []);

            if (! is_array($rawLabels)) {
                $rawLabels = [];
            }

            $labelNames = [];

            foreach ($rawLabels as $label) {
                if (! is_array($label)) {
                    continue;
                }

                $labelValue = Arr::get($label, 'label');

                if (is_string($labelValue) && $labelValue !== '') {
                    $labelNames[] = $labelValue;
                }
            }

            $combinedTerms = array_merge([$preferredName], $aliases, $acronyms, $labelNames);
            $searchTerms = array_values(array_unique($combinedTerms));

            $entry = [
                'value' => $preferredName,
                'rorId' => $identifier,
                'country' => Arr::get($decoded, 'country.country_name'),
                'countryCode' => Arr::get($decoded, 'country.country_code'),
                'searchTerms' => $searchTerms,
            ];

            $encoded = json_encode($entry, JSON_THROW_ON_ERROR);

            if (! $first) {
                fwrite($outputHandle, ',');
            } else {
                $first = false;
            }

            fwrite($outputHandle, $encoded);
            $count++;
        }

        fwrite($outputHandle, '],"total":'.$count.'}');
        fclose($outputHandle);
        gzclose($resource);

        return $count;
    }

    private function fundrefIndexTargetPath(string $affiliationTargetPath): string
    {
        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            return dirname($affiliationTargetPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
        }

        return storage_path('app/private/'.self::FUNDREF_INDEX_RELATIVE_PATH);
    }

    private function buildFundrefIndex(string $dumpPath, bool $isZip, string $targetPath): int
    {
        return $isZip
            ? $this->buildFundrefIndexFromZip($dumpPath, $targetPath)
            : $this->buildFundrefIndexFromGzip($dumpPath, $targetPath);
    }

    private function buildFundrefIndexFromZip(string $zipPath, string $targetPath): int
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive for FundRef indexing.');
        }

        $jsonFile = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (is_string($filename) && Str::endsWith($filename, '.json')) {
                if (! Str::contains($filename, 'schema_v2')) {
                    $jsonFile = $filename;

                    break;
                }

                if ($jsonFile === null) {
                    $jsonFile = $filename;
                }
            }
        }

        if ($jsonFile === null) {
            $zip->close();

            throw new \RuntimeException('No JSON file found in the ROR data archive for FundRef indexing.');
        }

        $jsonContent = $zip->getFromName($jsonFile);
        $zip->close();

        if ($jsonContent === false) {
            throw new \RuntimeException(sprintf('Failed to extract %s from archive for FundRef indexing.', $jsonFile));
        }

        $tempJsonPath = (string) tempnam(sys_get_temp_dir(), 'ror-fundref-json-');
        File::put($tempJsonPath, $jsonContent);

        try {
            return $this->buildFundrefIndexFromJsonFile($tempJsonPath, $targetPath);
        } finally {
            File::delete($tempJsonPath);
        }
    }

    private function buildFundrefIndexFromJsonFile(string $sourcePath, string $targetPath): int
    {
        $jsonContent = file_get_contents($sourcePath);

        if ($jsonContent === false) {
            throw new \RuntimeException("Failed to read file: {$sourcePath}");
        }

        $organizations = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        unset($jsonContent);

        if (! is_array($organizations)) {
            throw new \RuntimeException('Invalid JSON structure in ROR data file.');
        }

        $timestamp = Carbon::now()->toIso8601String();
        $source = $this->fundrefIndexSource($timestamp);
        $outputHandle = $this->openFundrefIndex($targetPath, $timestamp, $source);
        $first = true;
        $count = 0;

        foreach ($organizations as $decoded) {
            if (! is_array($decoded)) {
                continue;
            }

            $candidate = $this->processFundrefCandidate($decoded, $source);

            if ($candidate === null) {
                continue;
            }

            $this->writeFundrefCandidate($outputHandle, $candidate, $first);
            $count++;
        }

        $this->closeFundrefIndex($outputHandle, $count);

        return $count;
    }

    private function buildFundrefIndexFromGzip(string $sourcePath, string $targetPath): int
    {
        $resource = gzopen($sourcePath, 'rb');

        if ($resource === false) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive for FundRef indexing.');
        }

        $timestamp = Carbon::now()->toIso8601String();
        $source = $this->fundrefIndexSource($timestamp);
        $outputHandle = $this->openFundrefIndex($targetPath, $timestamp, $source);
        $first = true;
        $count = 0;

        while (! gzeof($resource)) {
            $line = gzgets($resource);

            if ($line === false) {
                break;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->warn(sprintf('Skipping malformed JSON line during FundRef indexing: %s', $exception->getMessage()));

                continue;
            }

            $candidate = $this->processFundrefCandidate($decoded, $source);

            if ($candidate === null) {
                continue;
            }

            $this->writeFundrefCandidate($outputHandle, $candidate, $first);
            $count++;
        }

        $this->closeFundrefIndex($outputHandle, $count);
        gzclose($resource);

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function fundrefIndexSource(string $timestamp): array
    {
        return [
            'source' => 'ror_fundref_index',
            'source_file' => self::FUNDREF_INDEX_RELATIVE_PATH,
            'source_generated_by' => 'get-ror-ids',
            'source_generated_from' => 'ROR Zenodo data dump',
            'source_retrieved_at' => $timestamp,
            'matching_strategy' => 'exact_fundref_external_id',
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return resource
     */
    private function openFundrefIndex(string $targetPath, string $timestamp, array $source): mixed
    {
        $outputHandle = fopen($targetPath, 'wb');

        if ($outputHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open FundRef index path [%s] for writing.', $targetPath));
        }

        fwrite($outputHandle, '{"lastUpdated":'.json_encode($timestamp, JSON_THROW_ON_ERROR).',"source":'.json_encode($source, JSON_THROW_ON_ERROR).',"data":[');

        return $outputHandle;
    }

    /**
     * @param  resource  $outputHandle
     * @param  array<string, mixed>  $candidate
     */
    private function writeFundrefCandidate(mixed $outputHandle, array $candidate, bool &$first): void
    {
        $encoded = json_encode($candidate, JSON_THROW_ON_ERROR);

        if (! $first) {
            fwrite($outputHandle, ',');
        } else {
            $first = false;
        }

        fwrite($outputHandle, $encoded);
    }

    /**
     * @param  resource  $outputHandle
     */
    private function closeFundrefIndex(mixed $outputHandle, int $count): void
    {
        fwrite($outputHandle, '],"total":'.$count.'}');
        fclose($outputHandle);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function processFundrefCandidate(array $decoded, array $source): ?array
    {
        $organization = $this->processOrganization($decoded);

        if ($organization === null) {
            return null;
        }

        $rorId = $this->canonicalRorIdentifier($organization['rorId']);
        $fundref = $this->extractFundrefExternalId($decoded);

        if ($rorId === null || $fundref === null) {
            return null;
        }

        $types = Arr::get($decoded, 'types', []);

        if (! is_array($types)) {
            $types = [];
        }

        return [
            'ror_id' => $rorId,
            'ror_display_name' => $organization['prefLabel'],
            'ror_status' => $this->stringValue(Arr::get($decoded, 'status')) ?? 'active',
            'ror_types' => array_values(array_filter(array_map('strval', $types))),
            'ror_record_last_modified' => $this->stringValue(Arr::get($decoded, 'updated'))
                ?? $this->stringValue(Arr::get($decoded, 'last_modified')),
            'external_ids' => [
                'fundref' => $fundref,
            ],
            'names' => $organization['otherLabel'],
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{all: list<string>, preferred: string|null}|null
     */
    private function extractFundrefExternalId(array $decoded): ?array
    {
        $externalIds = Arr::get($decoded, 'external_ids', []);

        if (! is_array($externalIds)) {
            return null;
        }

        if (array_is_list($externalIds)) {
            foreach ($externalIds as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if (mb_strtolower((string) Arr::get($entry, 'type')) === 'fundref') {
                    return $this->normalizeFundrefExternalId($entry);
                }
            }
        }

        foreach ($externalIds as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (in_array(mb_strtolower((string) $key), ['fundref', 'fundref id'], true)) {
                return $this->normalizeFundrefExternalId($entry);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{all: list<string>, preferred: string|null}|null
     */
    private function normalizeFundrefExternalId(array $entry): ?array
    {
        $all = Arr::get($entry, 'all', []);

        if (is_string($all) || is_numeric($all)) {
            $all = [$all];
        }

        if (! is_array($all)) {
            return null;
        }

        $values = array_values(array_unique(array_filter(
            array_map(fn (mixed $value): ?string => $this->fundrefValue($value), $all),
            fn (?string $value): bool => $value !== null,
        )));

        if ($values === []) {
            return null;
        }

        $preferred = $this->fundrefValue(Arr::get($entry, 'preferred'));

        return [
            'all' => $values,
            'preferred' => $preferred,
        ];
    }

    private function fundrefValue(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null || ! preg_match('/^[0-9]+$/', $value)) {
            return null;
        }

        return $value;
    }

    private function canonicalRorIdentifier(string $identifier): ?string
    {
        if (preg_match('#^(?:https?://)?(?:www\.)?ror\.org/([a-z0-9]{9})/?$#i', trim($identifier), $matches)) {
            return 'https://ror.org/'.strtolower($matches[1]);
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
