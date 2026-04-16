<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EuroSciVocParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch the European Science Vocabulary (EuroSciVoc) from the
 * EU Publications Office and save as hierarchical JSON file.
 */
#[Description('Fetch European Science Vocabulary (EuroSciVoc) from EU Publications Office and save as hierarchical JSON')]
#[Signature('get-euroscivoc')]
class GetEuroSciVoc extends Command
{
    private const OUTPUT_FILE = 'euroscivoc.json';

    public function handle(): int
    {
        $this->info('Fetching European Science Vocabulary (EuroSciVoc)...');

        try {
            $downloadUrl = (string) config('euroscivoc.download_url');
            $conceptSchemeUri = (string) config('euroscivoc.concept_scheme_uri');
            $schemeName = (string) config('euroscivoc.scheme_name');

            // Step 1: Download RDF file
            $this->info('Downloading RDF from EU Publications Office...');
            $response = Http::timeout(120)
                ->withOptions(['allow_redirects' => true])
                ->get($downloadUrl);

            if (! $response->successful()) {
                $this->error("Failed to download EuroSciVoc RDF: HTTP {$response->status()}");

                return Command::FAILURE;
            }

            $rdfContent = $response->body();
            $this->info('Downloaded '.number_format(strlen($rdfContent)).' bytes');

            // Step 2: Parse concepts
            $this->info('Parsing RDF/SKOS concepts...');
            $parser = new EuroSciVocParser;
            $concepts = $parser->extractConcepts($rdfContent, $conceptSchemeUri);
            $this->info('Extracted '.count($concepts).' concepts');

            if ($concepts === []) {
                $this->error('No concepts found in the RDF file. The file format may have changed.');

                return Command::FAILURE;
            }

            // Step 3: Build hierarchy
            $this->info('Building hierarchical structure...');
            $hierarchicalData = $parser->buildHierarchy($concepts, $schemeName, $conceptSchemeUri);

            $totalInHierarchy = $parser->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalInHierarchy} concepts");

            // Step 4: Save as JSON
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
                '<fg=green>✓</fg=green> Successfully saved European Science Vocabulary (EuroSciVoc)',
                ''
            );
            $this->components->twoColumnDetail('File', $filePath);
            $this->components->twoColumnDetail('Size', number_format($fileSize).' bytes');
            $this->components->twoColumnDetail('Last Updated', $hierarchicalData['lastUpdated']);
            $this->components->twoColumnDetail('Total Concepts', number_format($totalInHierarchy));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            $this->error('Trace: '.$e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
