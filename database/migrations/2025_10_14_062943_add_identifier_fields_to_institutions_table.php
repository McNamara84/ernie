<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Add new identifier fields to support different identifier types (DataCite 4.6)
            // Examples: ROR, labid (for MSL Labs), ISNI, GRID, etc.
            $table->string('identifier')->nullable()->after('ror_id');
            $table->string('identifier_type', 50)->nullable()->after('identifier');
            
            // Add indices for performance
            $table->index('identifier');
            $table->index('identifier_type');
            
            // Drop old unique constraint on (name, ror_id) using explicit index name
            $table->dropUnique('institutions_name_ror_id_unique');
        });
        
        // Migrate existing ROR data to new fields
        DB::table('institutions')
            ->whereNotNull('ror_id')
            ->update([
                'identifier' => DB::raw('ror_id'),
                'identifier_type' => 'ROR'
            ]);
        
        // Add new unique constraint on (identifier, identifier_type)
        Schema::table('institutions', function (Blueprint $table) {
            $table->unique(['identifier', 'identifier_type'], 'institutions_identifier_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Remove new unique constraint
            $table->dropUnique('institutions_identifier_unique');
            
            // Remove new fields
            $table->dropIndex(['identifier']);
            $table->dropIndex(['identifier_type']);
            $table->dropColumn(['identifier', 'identifier_type']);
            
            // Restore old unique constraint using explicit index name
            $table->unique(['name', 'ror_id'], 'institutions_name_ror_id_unique');
        });
    }
};
