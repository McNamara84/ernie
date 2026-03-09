<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ArdcApiService;
use App\Support\ChronostratVocabularyParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch the International Chronostratigraphic Chart from the ARDC Linked Data API
 * and save as hierarchical JSON file.
 */
class GetChronostratTimescale extends Command
{
    protected $signature = 'get-chronostrat-timescale';

    protected $description = 'Fetch ICS Chronostratigraphic Timescale from ARDC API and save as hierarchical JSON';

    private const OUTPUT_FILE = 'chronostrat-timescale.json';

    public function handle(): int
    {
        $this->info('Fetching ICS Chronostratigraphic Timescale from ARDC API...');

        try {
            $parser = new ChronostratVocabularyParser;
            $ardcApi = new ArdcApiService;

            $allItems = $ardcApi->fetchAllItems();
            $this->info('Fetched '.count($allItems).' items from ARDC API');

            // Parse concepts (filters out boundaries automatically)
            $this->info('Parsing concepts...');
            $concepts = $parser->extractConcepts($allItems);
            $this->info('Extracted '.count($concepts).' interval concepts');

            // Build hierarchical structure
            $this->info('Building hierarchical structure...');
            $hierarchicalData = $parser->buildHierarchy($concepts);

            $totalConcepts = $parser->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalConcepts} concepts");

            // Save as JSON
            $this->info('Saving to '.self::OUTPUT_FILE.'...');
            $json = json_encode($hierarchicalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                $this->error('Failed to encode data as JSON');

                return Command::FAILURE;
            }

            Storage::put(self::OUTPUT_FILE, $json);

            // Invalidate vocabulary caches
            $this->call('cache:clear-app', ['category' => 'vocabularies']);

            $filePath = Storage::path(self::OUTPUT_FILE);
            $fileSize = Storage::size(self::OUTPUT_FILE);

            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>✓</fg=green> Successfully saved ICS Chronostratigraphic Timescale',
                ''
            );
            $this->components->twoColumnDetail('File', $filePath);
            $this->components->twoColumnDetail('Size', number_format($fileSize).' bytes');
            $this->components->twoColumnDetail('Last Updated', $hierarchicalData['lastUpdated']);
            $this->components->twoColumnDetail('Total Concepts', number_format($totalConcepts));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            $this->error('Trace: '.$e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
