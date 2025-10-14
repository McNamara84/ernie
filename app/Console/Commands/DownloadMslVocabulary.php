<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MslVocabularyService;
use Illuminate\Console\Command;

class DownloadMslVocabulary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vocabulary:download-msl';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download and transform MSL vocabulary from GitHub';

    /**
     * Execute the console command.
     */
    public function handle(MslVocabularyService $service): int
    {
        $this->info('Downloading MSL vocabulary from GitHub...');
        $this->newLine();

        $success = $service->downloadAndTransformVocabulary();

        if ($success) {
            $vocabulary = $service->getVocabulary();
            $count = count($vocabulary);

            $this->info("✓ MSL vocabulary downloaded and transformed successfully!");
            $this->info("✓ {$count} concepts extracted");
            $this->info("✓ Saved to: storage/app/private/msl-vocabulary.json");
            $this->newLine();

            // Show sample concepts
            if ($count > 0) {
                $this->info('Sample concepts:');
                $samples = array_slice($vocabulary, 0, 5);
                foreach ($samples as $concept) {
                    $this->line("  - {$concept['text']}");
                }
                if ($count > 5) {
                    $this->line("  ... and " . ($count - 5) . " more");
                }
            }

            return Command::SUCCESS;
        }

        $this->error('✗ Failed to download MSL vocabulary');
        $this->error('  Check the logs for more details');

        return Command::FAILURE;
    }
}
