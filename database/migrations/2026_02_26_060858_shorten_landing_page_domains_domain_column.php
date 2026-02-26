<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Shorten the `domain` column on `landing_page_domains` from VARCHAR(2048) to VARCHAR(768).
 *
 * The original migration was updated for fresh installs, but existing environments
 * that already ran it still have VARCHAR(2048), which exceeds the MySQL InnoDB
 * unique-index key-length limit (3072 bytes with utf8mb4). This migration ensures
 * all environments converge on VARCHAR(768).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('landing_page_domains')) {
            return;
        }

        $driver = DB::getDriverName();

        // Truncate any existing values that exceed the new limit.
        // Only needed on MySQL/MariaDB where old migrations created VARCHAR(2048).
        // SQLite (used in CI) always runs fresh migrations with VARCHAR(768).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $oversizedIds = DB::table('landing_page_domains')
                ->whereRaw('CHAR_LENGTH(domain) > 768')
                ->pluck('id');

            if ($oversizedIds->isNotEmpty()) {
                // Check for potential collisions: if two oversized domains share the
                // same first 768 characters, truncation would violate the unique index.
                $collisionCount = (int) DB::table('landing_page_domains')
                    ->whereIn('id', $oversizedIds)
                    ->selectRaw('SUBSTR(domain, 1, 768) as truncated')
                    ->groupBy('truncated')
                    ->havingRaw('COUNT(*) > 1')
                    ->count();

                if ($collisionCount > 0) {
                    Log::error('Cannot truncate landing_page_domains.domain: truncation would create duplicate values', [
                        'collision_groups' => $collisionCount,
                        'affected_ids' => $oversizedIds->all(),
                    ]);
                    throw new \RuntimeException(
                        "Cannot shorten domain column: {$collisionCount} collision group(s) detected. "
                        . 'Resolve duplicate domains manually before re-running this migration.'
                    );
                }

                Log::warning('Truncating landing_page_domains entries that exceed 768 characters', [
                    'count' => $oversizedIds->count(),
                    'affected_ids' => $oversizedIds->all(),
                ]);

                DB::table('landing_page_domains')
                    ->whereIn('id', $oversizedIds)
                    ->update(['domain' => DB::raw('SUBSTR(domain, 1, 768)')]);
            }
        }

        Schema::table('landing_page_domains', function (Blueprint $table): void {
            $table->string('domain', 768)->change();
        });
    }
};
