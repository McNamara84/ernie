<?php

namespace App\Console\Commands;

use App\Support\GcmdVocabularyParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class BaseGcmdCommand extends Command
{
    /**
     * The NASA KMS API base URL
     *
     * @var string
     */
    protected const NASA_KMS_BASE_URL = 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/';

    /**
     * Get the vocabulary type (e.g., 'sciencekeywords', 'platforms', 'instruments')
     */
    abstract protected function getVocabularyType(): string;

    /**
     * Get the output filename
     */
    abstract protected function getOutputFile(): string;

    /**
     * Get the scheme title (e.g., 'NASA/GCMD Earth Science Keywords')
     */
    abstract protected function getSchemeTitle(): string;

    /**
     * Get the scheme URI
     */
    abstract protected function getSchemeURI(): string;

    /**
     * Get the display name for output messages
     */
    abstract protected function getDisplayName(): string;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $vocabularyType = $this->getVocabularyType();
        $url = self::NASA_KMS_BASE_URL.$vocabularyType.'?format=rdf';

        $this->info("Fetching {$this->getDisplayName()} from NASA KMS API...");
        $this->line('URL: '.$url);

        try {
            $parser = new GcmdVocabularyParser;
            $allConcepts = [];
            $pageNum = 1;
            $pageSize = 2000;
            $totalHits = null;

            // Fetch all pages
            do {
                $pageUrl = "{$url}&page_num={$pageNum}&page_size={$pageSize}";
                $this->info("Fetching page {$pageNum}...");

                $response = Http::timeout(60)
                    ->accept('application/rdf+xml')
                    ->get($pageUrl);

                if (! $response->successful()) {
                    $this->error('Failed to fetch data from NASA KMS API');
                    $this->error('Status: '.$response->status());

                    return Command::FAILURE;
                }

                $rdfContent = $response->body();

                // Parse metadata from first page
                if ($pageNum === 1) {
                    $totalHits = $parser->extractTotalHits($rdfContent);
                    $this->info("Total concepts to fetch: {$totalHits}");
                }

                // Parse concepts from this page
                $concepts = $parser->extractConcepts($rdfContent);
                $allConcepts = array_merge($allConcepts, $concepts);

                $this->info('Fetched '.count($concepts).' concepts (total: '.count($allConcepts).')');

                $pageNum++;

            } while (count($allConcepts) < $totalHits);

            $this->info('Successfully fetched all RDF data');

            // Build hierarchical structure
            $this->info('Building hierarchical structure...');
            $hierarchicalData = $parser->buildHierarchy(
                $allConcepts,
                $this->getSchemeTitle(),
                $this->getSchemeURI()
            );

            // Count total concepts
            $totalConcepts = $this->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalConcepts} concepts");

            // Save as JSON
            $outputFile = $this->getOutputFile();
            $this->info("Saving to {$outputFile}...");
            $json = json_encode($hierarchicalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                $this->error('Failed to encode data as JSON');

                return Command::FAILURE;
            }

            Storage::put($outputFile, $json);

            $filePath = Storage::path($outputFile);
            $fileSize = Storage::size($outputFile);

            $this->newLine();
            $this->components->twoColumnDetail(
                "<fg=green>✓</fg=green> Successfully saved {$this->getDisplayName()}",
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

    /**
     * Recursively count all concepts in the hierarchy
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    protected function countConcepts(array $data): int
    {
        $count = count($data);

        foreach ($data as $item) {
            if (isset($item['children']) && is_array($item['children']) && count($item['children']) > 0) {
                $count += $this->countConcepts($item['children']);
            }
        }

        return $count;
    }
}
