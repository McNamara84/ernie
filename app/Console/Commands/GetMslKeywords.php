<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MslVocabularyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fetch MSL keywords from GitHub and save as JSON')]
#[Signature('get-msl-keywords')]
class GetMslKeywords extends Command
{

    /**
     * Execute the console command.
     */
    public function handle(MslVocabularyService $service): int
    {
        $this->info('Fetching MSL keywords from GitHub...');
        $this->newLine();

        $success = $service->downloadAndTransformVocabulary();

        if ($success) {
            $vocabulary = $service->getVocabulary();
            $count = count($vocabulary);

            // Invalidate vocabulary caches after successful update
            $this->call('cache:clear-app', ['category' => 'vocabularies']);

            $this->info('✓ MSL keywords downloaded and transformed successfully!');
            $this->info("✓ {$count} concepts extracted");
            $this->info('✓ Saved to: storage/app/private/msl-vocabulary.json');
            $this->newLine();

            // Show sample concepts
            if ($count > 0) {
                $this->info('Sample concepts:');
                $samples = array_slice($vocabulary, 0, 5);
                foreach ($samples as $concept) {
                    $this->line("  - {$concept['text']}");
                }
                if ($count > 5) {
                    $this->line('  ... and '.($count - 5).' more');
                }
            }

            return Command::SUCCESS;
        }

        $this->error('✗ Failed to download MSL keywords');
        $this->error('  Check the logs for more details');

        return Command::FAILURE;
    }
}
