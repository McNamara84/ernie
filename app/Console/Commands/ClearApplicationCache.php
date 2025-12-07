<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Command to selectively clear application caches by category.
 *
 * This command provides fine-grained control over cache invalidation,
 * allowing administrators to clear specific cache categories without
 * affecting the entire cache system.
 */
class ClearApplicationCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-app
                            {category? : Cache category to clear (resources, vocabularies, ror, orcid, system, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear application caches by category';

    /**
     * Check if the current cache store supports tagging.
     */
    private function supportsTagging(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $category = $this->argument('category') ?? 'all';

        $validCategories = ['resources', 'vocabularies', 'ror', 'orcid', 'affiliations', 'system', 'all'];

        if (! in_array($category, $validCategories, true)) {
            $this->error("Invalid category. Valid categories: ".implode(', ', $validCategories));

            return self::FAILURE;
        }

        if ($category === 'all') {
            $this->clearAllCaches();
        } else {
            $this->clearCacheByTag($category);
        }

        $this->info("✓ Cache category '{$category}' cleared successfully.");

        return self::SUCCESS;
    }

    /**
     * Clear all application caches.
     */
    private function clearAllCaches(): void
    {
        if ($this->supportsTagging()) {
            $tags = ['resources', 'vocabularies', 'ror', 'orcid', 'affiliations', 'system'];

            foreach ($tags as $tag) {
                Cache::tags([$tag])->flush();
            }
        } else {
            // Without tagging, clear entire cache store
            Cache::flush();
        }
    }

    /**
     * Clear cache by specific tag.
     *
     * @param string $tag Cache tag to clear
     */
    private function clearCacheByTag(string $tag): void
    {
        if ($this->supportsTagging()) {
            Cache::tags([$tag])->flush();
        } else {
            // Without tagging, clear entire cache store
            Cache::flush();
        }
    }
}
