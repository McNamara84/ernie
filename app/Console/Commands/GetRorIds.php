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

        if (!$record || !is_array($record)) {
            $this->error('No ROR data dump records were returned.');

            return self::FAILURE;
        }

        $files = Arr::get($record, 'files', []);
        $dataFile = collect($files)
            ->filter(fn ($file) => is_array($file))
            ->first(function ($file) {
                $key = Arr::get($file, 'key');

                return is_string($key) && Str::endsWith($key, ['.jsonl.gz', '.json.gz']);
            });

        if (!$dataFile) {
            $this->error('Unable to locate a JSONL data dump within the ROR record.');

            return self::FAILURE;
        }

        $downloadUrl = Arr::get($dataFile, 'links.download') ?? Arr::get($dataFile, 'links.self');

        if (!is_string($downloadUrl) || $downloadUrl === '') {
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
            : storage_path('app/'.self::OUTPUT_RELATIVE_PATH);

        $directory = dirname($targetPath);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0o755, true);
        }

        try {
            $saved = $this->convertDumpToSuggestions($temporaryPath, $targetPath);
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

    /**
     * @return int<number-of-saved-records>
     */
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

        while (!gzeof($resource)) {
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

            if (!is_string($preferredName) || !is_string($identifier) || $preferredName === '' || $identifier === '') {
                continue;
            }

            $aliases = array_filter(
                array_map('strval', Arr::get($decoded, 'aliases', [])),
                fn (string $alias) => $alias !== ''
            );

            $acronyms = array_filter(
                array_map('strval', Arr::get($decoded, 'acronyms', [])),
                fn (string $alias) => $alias !== ''
            );

            $labelNames = collect(Arr::get($decoded, 'labels', []))
                ->map(fn ($label) => is_array($label) ? Arr::get($label, 'label') : null)
                ->filter(fn ($label) => is_string($label) && $label !== '')
                ->values()
                ->all();

            $searchTerms = collect([$preferredName])
                ->merge($aliases)
                ->merge($acronyms)
                ->merge($labelNames)
                ->unique()
                ->values()
                ->all();

            $entry = [
                'value' => $preferredName,
                'rorId' => $identifier,
                'country' => Arr::get($decoded, 'country.country_name'),
                'countryCode' => Arr::get($decoded, 'country.country_code'),
                'searchTerms' => $searchTerms,
            ];

            $encoded = json_encode($entry, JSON_THROW_ON_ERROR);

            if (!$first) {
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
