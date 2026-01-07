<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Traits\ChecksCacheTagging;
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
    use ChecksCacheTagging;

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
     * Execute the console command.
     */
    public function handle(): int
    {
        $category = $this->argument('category') ?? 'all';

        $validCategories = ['resources', 'vocabularies', 'ror', 'orcid', 'system', 'all'];

        if (! in_array($category, $validCategories, true)) {
            $this->error('Invalid category. Valid categories: '.implode(', ', $validCategories));

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
     *
     * WARNING: When cache tagging is not supported (e.g., file/database drivers),
     * this will call Cache::flush() which clears the ENTIRE cache store,
     * including sessions, rate limiting, and any other cached data.
     */
    private function clearAllCaches(): void
    {
        if ($this->supportsTagging()) {
            // Note: 'affiliations' is a secondary tag for 'ror', not a separate category
            $tags = ['resources', 'vocabularies', 'ror', 'orcid', 'system'];

            foreach ($tags as $tag) {
                Cache::tags([$tag])->flush();
            }
        } else {
            // WARNING: This clears the ENTIRE cache store (sessions, rate limiting, etc.)
            Cache::flush();
            $this->warn('⚠️  Cache tagging not supported. Cleared entire cache store.');
        }
    }

    /**
     * Clear cache by specific tag.
     *
     * Note: Laravel's cache tag flushing uses OR logic. Flushing a single tag
     * (e.g., 'ror') will clear all items tagged with that tag, regardless of
     * other tags. For example, items tagged with ['ror', 'affiliations'] will
     * be cleared when flushing 'ror' alone.
     *
     * WARNING: When cache tagging is not supported (e.g., file/database drivers),
     * this will call Cache::flush() which clears the ENTIRE cache store,
     * not just the requested category. This affects all application caches
     * including sessions, rate limiting, and other cached data.
     *
     * @param  string  $tag  Cache tag to clear
     */
    private function clearCacheByTag(string $tag): void
    {
        if ($this->supportsTagging()) {
            Cache::tags([$tag])->flush();
        } else {
            // WARNING: Cannot clear specific category without tagging support.
            // This clears the ENTIRE cache store (sessions, rate limiting, etc.)
            Cache::flush();
            $this->warn("⚠️  Cache tagging not supported. Cleared entire cache store instead of just '{$tag}'.");
        }
    }
}
