<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class GetRorIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-ror-ids {--output= : Override the output file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch the latest ROR affiliation identifiers and cache them as JSON.';

    private const METADATA_URL = 'https://zenodo.org/api/records/';

    private const COMMUNITY = 'ror-data';

    private const OUTPUT_RELATIVE_PATH = 'ror/ror-affiliations.json';

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
        assert($metadataResponse instanceof \Illuminate\Http\Client\Response);

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
        assert($downloadResponse instanceof \Illuminate\Http\Client\Response);

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

        $directory = dirname($targetPath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0o755, true);
        }

        try {
            $fileKey = Arr::get($dataFile, 'key', '');
            $isZip = is_string($fileKey) && Str::endsWith($fileKey, '.zip');

            if ($isZip) {
                $saved = $this->processZipDump($temporaryPath, $targetPath);
            } else {
                $saved = $this->convertDumpToSuggestions($temporaryPath, $targetPath);
            }
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

        $this->info(sprintf('Saved %d ROR affiliation entries to %s', $saved, $targetPath));

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

        fwrite($outputHandle, '[');

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

        fwrite($outputHandle, ']');
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

        fwrite($outputHandle, '[');

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

        fwrite($outputHandle, ']');
        fclose($outputHandle);
        gzclose($resource);

        return $count;
    }
}
