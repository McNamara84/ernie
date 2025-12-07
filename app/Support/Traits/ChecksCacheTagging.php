<?php

declare(strict_types=1);

namespace App\Support\Traits;

use Illuminate\Support\Facades\Cache;

/**
 * Trait to check if the current cache store supports tagging.
 *
 * This trait provides a common method for checking cache tagging support
 * across different cache-related classes.
 */
trait ChecksCacheTagging
{
    /**
     * Check if the current cache store supports tagging.
     *
     * Cache tagging is supported by Redis and Memcached drivers,
     * but not by file, database, or array drivers.
     *
     * @return bool True if tagging is supported, false otherwise
     */
    private function supportsTagging(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }
}
