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

        $temporaryTargetPath = $this->temporaryOutputPath($targetPath);
        $temporaryFundrefIndexPath = $this->temporaryOutputPath($fundrefIndexPath);

        try {
            $fileKey = Arr::get($dataFile, 'key', '');
            $isZip = is_string($fileKey) && Str::endsWith($fileKey, '.zip');

            $counts = $isZip
                ? $this->processZipDump($temporaryPath, $temporaryTargetPath, $temporaryFundrefIndexPath)
                : $this->processGzipDump($temporaryPath, $temporaryTargetPath, $temporaryFundrefIndexPath);

            $saved = $counts['affiliations'];
            if ($saved === 0) {
                throw new \RuntimeException('No ROR affiliations were written.');
            }

            $fundrefSaved = $counts['fundref'];

            $this->commitOutputFiles($temporaryTargetPath, $targetPath, $temporaryFundrefIndexPath, $fundrefIndexPath);
        } catch (Throwable $exception) {
            File::delete([$temporaryPath, $temporaryTargetPath, $temporaryFundrefIndexPath]);
            $this->error(sprintf('Failed to process ROR data dump: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        File::delete($temporaryPath);

        // Invalidate ROR caches after successful update
        $this->call('cache:clear-app', ['category' => 'ror']);

        $this->info(sprintf('Saved %d ROR affiliation entries to %s', $saved, $targetPath));
        $this->info(sprintf('Saved %d ROR FundRef candidate entries to %s', $fundrefSaved, $fundrefIndexPath));

        return self::SUCCESS;
    }

    private function temporaryOutputPath(string $targetPath): string
    {
        return dirname($targetPath)
            .DIRECTORY_SEPARATOR
            .'.'.basename($targetPath).'.'.(string) Str::uuid().'.tmp';
    }

    private function commitOutputFiles(
        string $temporaryAffiliationsPath,
        string $affiliationsPath,
        string $temporaryFundrefIndexPath,
        string $fundrefIndexPath,
    ): void {
        $this->assertMovableOutput($temporaryAffiliationsPath, 'Temporary affiliations file');
        $this->assertMovableOutput($temporaryFundrefIndexPath, 'Temporary FundRef index file');
        $this->assertCommitTarget($affiliationsPath, 'Affiliations target');
        $this->assertCommitTarget($fundrefIndexPath, 'FundRef index target');

        if ($affiliationsPath === $fundrefIndexPath) {
            throw new \RuntimeException('Affiliations and FundRef index targets must be distinct file paths.');
        }

        $affiliationsBackupPath = $this->temporaryOutputPath($affiliationsPath).'.bak';
        $fundrefBackupPath = $this->temporaryOutputPath($fundrefIndexPath).'.bak';
        $affiliationsBackedUp = false;
        $fundrefBackedUp = false;
        $affiliationsCommitted = false;
        $fundrefCommitted = false;

        try {
            if (File::exists($affiliationsPath)) {
                $this->moveOutputFile($affiliationsPath, $affiliationsBackupPath);
                $affiliationsBackedUp = true;
            }

            if (File::exists($fundrefIndexPath)) {
                $this->moveOutputFile($fundrefIndexPath, $fundrefBackupPath);
                $fundrefBackedUp = true;
            }

            $this->moveOutputFile($temporaryAffiliationsPath, $affiliationsPath);
            $affiliationsCommitted = true;

            $this->moveOutputFile($temporaryFundrefIndexPath, $fundrefIndexPath);
            $fundrefCommitted = true;

            File::delete([$affiliationsBackupPath, $fundrefBackupPath]);
        } catch (Throwable $exception) {
            if ($affiliationsCommitted) {
                File::delete($affiliationsPath);
            }

            if ($fundrefCommitted) {
                File::delete($fundrefIndexPath);
            }

            if ($affiliationsBackedUp && File::exists($affiliationsBackupPath)) {
                $this->moveOutputFile($affiliationsBackupPath, $affiliationsPath);
            }

            if ($fundrefBackedUp && File::exists($fundrefBackupPath)) {
                $this->moveOutputFile($fundrefBackupPath, $fundrefIndexPath);
            }

            throw $exception;
        } finally {
            File::delete([$affiliationsBackupPath, $fundrefBackupPath]);
        }
    }

    private function assertMovableOutput(string $path, string $label): void
    {
        if (! File::exists($path) || File::isDirectory($path)) {
            throw new \RuntimeException("{$label} does not exist or is not a file: {$path}");
        }
    }

    private function assertCommitTarget(string $path, string $label): void
    {
        if (File::isDirectory($path)) {
            throw new \RuntimeException("{$label} is a directory, expected a file path: {$path}");
        }
    }

    private function moveOutputFile(string $sourcePath, string $targetPath): void
    {
        $this->assertMovableOutput($sourcePath, 'Output source');

        if (File::isDirectory($targetPath)) {
            throw new \RuntimeException("Output target is a directory, expected a file path: {$targetPath}");
        }

        if (! @rename($sourcePath, $targetPath)) {
            throw new \RuntimeException("Failed to move output file into place: {$targetPath}");
        }
    }

    /**
     * @return array{affiliations: int, fundref: int}
     */
    private function processZipDump(string $zipPath, string $affiliationsTargetPath, string $fundrefIndexTargetPath): array
    {
        $tempJsonPath = $this->extractZipJsonToTemporaryFile($zipPath, 'ror-json-');

        try {
            return $this->processJsonDumpToOutputs($tempJsonPath, $affiliationsTargetPath, $fundrefIndexTargetPath);
        } finally {
            File::delete($tempJsonPath);
        }
    }

    private function extractZipJsonToTemporaryFile(string $zipPath, string $temporaryPrefix): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive.');
        }

        try {
            $jsonFile = $this->findJsonFileInZip($zip);

            if ($jsonFile === null) {
                throw new \RuntimeException('No JSON file found in the ROR data archive.');
            }

            $this->info(sprintf('Processing %s from archive...', $jsonFile));

            $source = $zip->getStream($jsonFile);

            if ($source === false) {
                throw new \RuntimeException(sprintf('Failed to stream %s from archive.', $jsonFile));
            }

            $tempJsonPath = (string) tempnam(sys_get_temp_dir(), $temporaryPrefix);
            $target = fopen($tempJsonPath, 'wb');

            if ($target === false) {
                fclose($source);

                throw new \RuntimeException(sprintf('Failed to open temporary JSON path for %s.', $jsonFile));
            }

            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($source);
                fclose($target);
            }

            return $tempJsonPath;
        } finally {
            $zip->close();
        }
    }

    private function findJsonFileInZip(\ZipArchive $zip): ?string
    {
        $jsonFile = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (is_string($filename) && Str::endsWith($filename, '.json')) {
                if (! Str::contains($filename, 'schema_v2')) {
                    return $filename;
                }

                $jsonFile ??= $filename;
            }
        }

        return $jsonFile;
    }

    /**
     * @return array{affiliations: int, fundref: int}
     */
    private function processJsonDumpToOutputs(string $sourcePath, string $affiliationsTargetPath, string $fundrefIndexTargetPath): array
    {
        $this->info('Loading JSON data into memory...');
        $jsonContent = file_get_contents($sourcePath);

        if ($jsonContent === false) {
            throw new \RuntimeException("Failed to read file: {$sourcePath}");
        }

        $this->info('Parsing JSON...');
        $organizations = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

        unset($jsonContent);

        if (! is_array($organizations)) {
            throw new \RuntimeException('Invalid JSON structure in ROR data file.');
        }

        $this->info(sprintf('Found %d organizations, processing...', count($organizations)));

        $timestamp = Carbon::now()->toIso8601String();
        $source = $this->fundrefIndexSource($timestamp);
        $affiliationsHandle = $this->openAffiliationSuggestions($affiliationsTargetPath, $timestamp);
        $fundrefHandle = null;
        $firstAffiliation = true;
        $firstFundref = true;
        $affiliationCount = 0;
        $fundrefCount = 0;

        try {
            $fundrefHandle = $this->openFundrefIndex($fundrefIndexTargetPath, $timestamp, $source);

            foreach ($organizations as $decoded) {
                if (! is_array($decoded)) {
                    continue;
                }

                $entry = $this->processOrganization($decoded);

                if ($entry === null) {
                    continue;
                }

                $this->writeAffiliationSuggestion($affiliationsHandle, $entry, $firstAffiliation);
                $affiliationCount++;

                if ($affiliationCount % 10000 === 0) {
                    $this->info(sprintf('Processed %d organizations...', $affiliationCount));
                }

                $candidate = $this->processFundrefCandidate($decoded, $source, $entry);

                if ($candidate !== null) {
                    $this->writeFundrefCandidate($fundrefHandle, $candidate, $firstFundref);
                    $fundrefCount++;
                }
            }

            $this->closeAffiliationSuggestions($affiliationsHandle, $affiliationCount);
            $affiliationsHandle = null;
            $this->closeFundrefIndex($fundrefHandle, $fundrefCount);
            $fundrefHandle = null;
        } finally {
            if (is_resource($affiliationsHandle)) {
                fclose($affiliationsHandle);
            }

            if (is_resource($fundrefHandle)) {
                fclose($fundrefHandle);
            }
        }

        return [
            'affiliations' => $affiliationCount,
            'fundref' => $fundrefCount,
        ];
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

    /**
     * @return array{affiliations: int, fundref: int}
     */
    private function processGzipDump(string $sourcePath, string $affiliationsTargetPath, string $fundrefIndexTargetPath): array
    {
        $resource = gzopen($sourcePath, 'rb');

        if ($resource === false) {
            throw new \RuntimeException('Unable to open the downloaded ROR data archive.');
        }

        $timestamp = Carbon::now()->toIso8601String();
        $source = $this->fundrefIndexSource($timestamp);
        $affiliationsHandle = $this->openAffiliationSuggestions($affiliationsTargetPath, $timestamp);
        $fundrefHandle = null;
        $firstAffiliation = true;
        $firstFundref = true;
        $affiliationCount = 0;
        $fundrefCount = 0;

        try {
            $fundrefHandle = $this->openFundrefIndex($fundrefIndexTargetPath, $timestamp, $source);

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
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    $this->warn(sprintf('Skipping malformed JSON line: %s', $exception->getMessage()));

                    continue;
                }

                if (! is_array($decoded)) {
                    continue;
                }

                $entry = $this->processOrganization($decoded);

                if ($entry === null) {
                    continue;
                }

                $this->writeAffiliationSuggestion($affiliationsHandle, $entry, $firstAffiliation);
                $affiliationCount++;

                $candidate = $this->processFundrefCandidate($decoded, $source, $entry);

                if ($candidate !== null) {
                    $this->writeFundrefCandidate($fundrefHandle, $candidate, $firstFundref);
                    $fundrefCount++;
                }
            }

            $this->closeAffiliationSuggestions($affiliationsHandle, $affiliationCount);
            $affiliationsHandle = null;
            $this->closeFundrefIndex($fundrefHandle, $fundrefCount);
            $fundrefHandle = null;
        } finally {
            if (is_resource($affiliationsHandle)) {
                fclose($affiliationsHandle);
            }

            if (is_resource($fundrefHandle)) {
                fclose($fundrefHandle);
            }

            gzclose($resource);
        }

        return [
            'affiliations' => $affiliationCount,
            'fundref' => $fundrefCount,
        ];
    }

    /**
     * @return resource
     */
    private function openAffiliationSuggestions(string $targetPath, string $timestamp): mixed
    {
        $outputHandle = fopen($targetPath, 'wb');

        if ($outputHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open output path [%s] for writing.', $targetPath));
        }

        fwrite($outputHandle, '{"lastUpdated":'.json_encode($timestamp, JSON_THROW_ON_ERROR).',"data":[');

        return $outputHandle;
    }

    /**
     * @param  resource  $outputHandle
     * @param  array<string, mixed>  $entry
     */
    private function writeAffiliationSuggestion(mixed $outputHandle, array $entry, bool &$first): void
    {
        $encoded = json_encode($entry, JSON_THROW_ON_ERROR);

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
    private function closeAffiliationSuggestions(mixed $outputHandle, int $count): void
    {
        fwrite($outputHandle, '],"total":'.$count.'}');
        fclose($outputHandle);
    }

    private function fundrefIndexTargetPath(string $affiliationTargetPath): string
    {
        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            return dirname($affiliationTargetPath).DIRECTORY_SEPARATOR.'ror-fundref-index.json';
        }

        return storage_path('app/private/'.self::FUNDREF_INDEX_RELATIVE_PATH);
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
     * @param  array{prefLabel: string, rorId: string, otherLabel: array<int, string>}|null  $organization
     * @return array<string, mixed>|null
     */
    private function processFundrefCandidate(array $decoded, array $source, ?array $organization = null): ?array
    {
        $organization ??= $this->processOrganization($decoded);

        if ($organization === null) {
            return null;
        }
        $rorId = $this->canonicalRorIdentifier($organization['rorId']);
        $fundref = $this->extractFundrefExternalId($decoded);

        if ($rorId === null || $fundref === null) {
            return null;
        }

        $status = $this->stringValue(Arr::get($decoded, 'status'));

        if ($status === null) {
            return null;
        }

        $types = Arr::get($decoded, 'types', []);

        if (! is_array($types)) {
            $types = [];
        }

        return [
            'ror_id' => $rorId,
            'ror_display_name' => $organization['prefLabel'],
            'ror_status' => $status,
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
        if (preg_match('#^(?:https?://)?(?:www\.)?ror\.org/(0[a-z0-9]{6}\d{2})/?$#i', trim($identifier), $matches)) {
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
