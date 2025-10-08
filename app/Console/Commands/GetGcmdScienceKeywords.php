<?php

namespace App\Console\Commands;

use App\Support\GcmdVocabularyParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GetGcmdScienceKeywords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-gcmd-science-keywords';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch GCMD Science Keywords from NASA KMS API and save as hierarchical JSON';

    /**
     * The NASA KMS API endpoint for GCMD Science Keywords
     *
     * @var string
     */
    protected const NASA_KMS_URL = 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords?format=rdf';

    /**
     * The output file path (relative to storage/app)
     *
     * @var string
     */
    protected const OUTPUT_FILE = 'gcmd-science-keywords.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching GCMD Science Keywords from NASA KMS API...');
        $this->line('URL: ' . self::NASA_KMS_URL);

        try {
            $allConcepts = [];
            $pageNum = 1;
            $pageSize = 2000;
            $totalHits = null;

            // Fetch all pages
            do {
                $url = self::NASA_KMS_URL . "&page_num={$pageNum}&page_size={$pageSize}";
                $this->info("Fetching page {$pageNum}...");

                $response = Http::timeout(60)
                    ->accept('application/rdf+xml')
                    ->get($url);

                if (!$response->successful()) {
                    $this->error('Failed to fetch data from NASA KMS API');
                    $this->error('Status: ' . $response->status());
                    return Command::FAILURE;
                }

                $rdfContent = $response->body();
                
                // Parse metadata from first page
                if ($pageNum === 1) {
                    $totalHits = $this->extractTotalHits($rdfContent);
                    $this->info("Total concepts to fetch: {$totalHits}");
                }

                // Parse concepts from this page
                $concepts = $this->extractConcepts($rdfContent);
                $allConcepts = array_merge($allConcepts, $concepts);
                
                $this->info("Fetched " . count($concepts) . " concepts (total: " . count($allConcepts) . ")");

                $pageNum++;

            } while (count($allConcepts) < $totalHits);

            $this->info('Successfully fetched all RDF data');

            // Build hierarchical structure
            $this->info('Building hierarchical structure...');
            $parser = new GcmdVocabularyParser();
            $hierarchicalData = $parser->buildHierarchy(
                $allConcepts,
                'NASA/GCMD Earth Science Keywords',
                'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords'
            );

            // Count total concepts
            $totalConcepts = $this->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalConcepts} concepts");

            // Save as JSON
            $this->info('Saving to ' . self::OUTPUT_FILE . '...');
            $json = json_encode($hierarchicalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            if ($json === false) {
                $this->error('Failed to encode data as JSON');
                return Command::FAILURE;
            }
            
            Storage::put(self::OUTPUT_FILE, $json);
            
            $filePath = Storage::path(self::OUTPUT_FILE);
            $fileSize = Storage::size(self::OUTPUT_FILE);
            
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=green>✓</fg=green> Successfully saved GCMD Science Keywords',
                ''
            );
            $this->components->twoColumnDetail('File', $filePath);
            $this->components->twoColumnDetail('Size', number_format($fileSize) . ' bytes');
            $this->components->twoColumnDetail('Last Updated', $hierarchicalData['lastUpdated']);
            $this->components->twoColumnDetail('Total Concepts', number_format($totalConcepts));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Extract total hits from RDF content
     */
    private function extractTotalHits(string $rdfContent): int
    {
        $xml = new \SimpleXMLElement($rdfContent);
        $xml->registerXPathNamespace('gcmd', 'https://gcmd.earthdata.nasa.gov/kms#');
        
        $hits = $xml->xpath('//gcmd:gcmd/gcmd:hits');
        
        return $hits ? (int) $hits[0] : 0;
    }

    /**
     * Extract concepts from RDF content
     * 
     * @return array<int, array<string, string|null>>
     */
    private function extractConcepts(string $rdfContent): array
    {
        $xml = new \SimpleXMLElement($rdfContent);
        $xml->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xml->registerXPathNamespace('skos', 'http://www.w3.org/2004/02/skos/core#');
        $xml->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');

        $conceptElements = $xml->xpath('//skos:Concept');
        
        if ($conceptElements === false || $conceptElements === null) {
            return [];
        }
        
        $concepts = [];

        foreach ($conceptElements as $concept) {
            $rdfNs = $concept->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $id = (string) ($rdfNs['about'] ?? '');
            
            // Convert UUID to full URL if necessary
            if ($id && !str_starts_with($id, 'http')) {
                $id = 'https://gcmd.earthdata.nasa.gov/kms/concept/' . $id;
            }
            
            $skosNs = $concept->children('http://www.w3.org/2004/02/skos/core#');
            $prefLabel = (string) ($skosNs->prefLabel ?? '');
            $definition = (string) ($skosNs->definition ?? '');
            
            // Get language (default to 'en')
            $language = 'en';
            if ($skosNs->prefLabel) {
                $langAttr = $skosNs->prefLabel->attributes('http://www.w3.org/XML/1998/namespace');
                if ($langAttr && isset($langAttr['lang'])) {
                    $language = (string) $langAttr['lang'];
                }
            }

            // Get broader relationship
            $broaderId = null;
            if ($skosNs->broader) {
                $broaderAttr = $skosNs->broader->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $broaderId = (string) ($broaderAttr['resource'] ?? '');
                
                // Convert UUID to full URL if necessary
                if ($broaderId && !str_starts_with($broaderId, 'http')) {
                    $broaderId = 'https://gcmd.earthdata.nasa.gov/kms/concept/' . $broaderId;
                }
            }

            $concepts[] = [
                'id' => $id,
                'text' => $prefLabel,
                'language' => $language,
                'description' => $definition,
                'broaderId' => $broaderId,
            ];
        }

        return $concepts;
    }

    /**
     * Recursively count all concepts in the hierarchy
     * 
     * @param array<int, array<string, mixed>> $data
     */
    private function countConcepts(array $data): int
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
