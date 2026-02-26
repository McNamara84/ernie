<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            DB::table('landing_page_domains')
                ->whereRaw('CHAR_LENGTH(domain) > 768')
                ->update(['domain' => DB::raw('SUBSTR(domain, 1, 768)')]);
        }

        Schema::table('landing_page_domains', function (Blueprint $table): void {
            $table->string('domain', 768)->change();
        });
    }
};
