<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ThesaurusSetting;
use App\Services\ArdcApiService;
use App\Support\AnalyticalMethodsVocabularyParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch the "Analytical Methods for Geochemistry and Cosmochemistry" vocabulary
 * from the ARDC Linked Data API and save as hierarchical JSON file.
 */
#[Description('Fetch Analytical Methods vocabulary from ARDC API and save as hierarchical JSON')]
#[Signature('get-analytical-methods')]
class GetAnalyticalMethods extends Command
{
    private const OUTPUT_FILE = 'analytical-methods.json';

    public function handle(): int
    {
        $version = $this->resolveVersion();
        $urlTemplate = (string) config('ardc.analytical_methods.url_template');
        $baseUrl = str_replace('{version}', $version, $urlTemplate);

        $this->info("Fetching Analytical Methods vocabulary (version {$version}) from ARDC API...");

        try {
            $parser = new AnalyticalMethodsVocabularyParser;
            $ardcApi = new ArdcApiService($baseUrl);

            $allItems = $ardcApi->fetchAllItems();
            $this->info('Fetched '.count($allItems).' items from ARDC API');

            $this->info('Parsing concepts...');
            $concepts = $parser->extractConcepts($allItems);
            $this->info('Extracted '.count($concepts).' concepts');

            $this->info('Building hierarchical structure...');
            $hierarchicalData = $parser->buildHierarchy($concepts);

            $totalConcepts = $parser->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalConcepts} concepts");

            $this->info('Saving to '.self::OUTPUT_FILE.'...');
            $json = json_encode($hierarchicalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                $this->error('Failed to encode data as JSON');

                return Command::FAILURE;
            }

            Storage::put(self::OUTPUT_FILE, $json);

            $this->call('cache:clear-app', ['category' => 'vocabularies']);

            $filePath = Storage::path(self::OUTPUT_FILE);
            $fileSize = Storage::size(self::OUTPUT_FILE);

            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>✓</fg=green> Successfully saved Analytical Methods vocabulary',
                ''
            );
            $this->components->twoColumnDetail('File', $filePath);
            $this->components->twoColumnDetail('Size', number_format($fileSize).' bytes');
            $this->components->twoColumnDetail('Version', $version);
            $this->components->twoColumnDetail('Last Updated', $hierarchicalData['lastUpdated']);
            $this->components->twoColumnDetail('Total Concepts', number_format($totalConcepts));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            $this->error('Trace: '.$e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Resolve the vocabulary version from the database or fall back to default.
     */
    private function resolveVersion(): string
    {
        $setting = ThesaurusSetting::where('type', ThesaurusSetting::TYPE_ANALYTICAL_METHODS)->first();

        if ($setting !== null && $setting->version !== null && $setting->version !== '') {
            return $setting->version;
        }

        return (string) config('ardc.analytical_methods.default_version');
    }
}
