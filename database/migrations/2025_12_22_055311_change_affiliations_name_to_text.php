<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop the index on name (required before converting to TEXT)
        DB::statement('DROP INDEX idx_affiliations_name ON affiliations');

        // 2. Change column type to TEXT
        DB::statement('ALTER TABLE affiliations MODIFY name TEXT NOT NULL');

        // 3. Recreate index with prefix length (first 191 chars for UTF8MB4)
        DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Drop the prefixed index
        DB::statement('DROP INDEX idx_affiliations_name ON affiliations');

        // 2. Change column back to VARCHAR
        DB::statement('ALTER TABLE affiliations MODIFY name VARCHAR(255) NOT NULL');

        // 3. Recreate full index
        DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name)');
    }
};
