<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GemetApiService;
use App\Support\GemetVocabularyParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch the GEMET Thesaurus from the EEA GEMET REST API
 * and save as hierarchical JSON file.
 */
class GetGemetThesaurus extends Command
{
    protected $signature = 'get-gemet-thesaurus';

    protected $description = 'Fetch GEMET Thesaurus from EEA API and save as hierarchical JSON';

    private const OUTPUT_FILE = 'gemet-thesaurus.json';

    public function handle(): int
    {
        $this->info('Fetching GEMET Thesaurus from EEA API...');

        try {
            $api = new GemetApiService;
            $parser = new GemetVocabularyParser;

            // Step 1: Fetch SuperGroups
            $this->info('Fetching super groups...');
            $superGroups = $api->fetchSuperGroups();
            $this->info('Fetched '.count($superGroups).' super groups');

            // Step 2: Fetch Groups
            $this->info('Fetching groups...');
            $groups = $api->fetchGroups();
            $this->info('Fetched '.count($groups).' groups');

            // Step 3: Map Groups to SuperGroups
            $this->info('Mapping groups to super groups...');
            $groupToSuperGroupMap = $api->fetchGroupToSuperGroupMapping($groups);
            $this->info('Mapped '.count($groupToSuperGroupMap).' groups to super groups');

            // Step 4: Fetch concepts for each group (concurrently)
            $this->info('Fetching concepts for each group...');
            $conceptsByGroup = $api->fetchAllConceptsByGroupConcurrently($groups);

            $totalConcepts = 0;
            foreach ($conceptsByGroup as $groupUri => $concepts) {
                $totalConcepts += count($concepts);
            }

            $this->info("Fetched {$totalConcepts} concept assignments (before deduplication)");

            // Step 5: Build hierarchy
            $this->info('Building hierarchical structure...');
            $hierarchicalData = $parser->buildHierarchy(
                $superGroups,
                $groups,
                $groupToSuperGroupMap,
                $conceptsByGroup
            );

            $totalInHierarchy = $parser->countConcepts($hierarchicalData['data']);
            $this->info("Built hierarchy with {$totalInHierarchy} nodes");

            // Step 6: Save as JSON
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
                '<fg=green>✓</fg=green> Successfully saved GEMET Thesaurus',
                ''
            );
            $this->components->twoColumnDetail('File', $filePath);
            $this->components->twoColumnDetail('Size', number_format($fileSize).' bytes');
            $this->components->twoColumnDetail('Last Updated', $hierarchicalData['lastUpdated']);
            $this->components->twoColumnDetail('Total Nodes', number_format($totalInHierarchy));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            $this->error('Trace: '.$e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
