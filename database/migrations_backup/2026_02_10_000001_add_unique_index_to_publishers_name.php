<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deduplicates publisher rows by name (keeping the oldest record per name
     * and reassigning foreign keys), then adds a unique index on publishers.name
     * to ensure updateOrCreate/firstOrCreate operations are race-safe under
     * concurrent requests (e.g., parallel CSV imports).
     */
    public function up(): void
    {
        // Deduplicate publishers by name before adding the unique constraint.
        // For each group of duplicates, keep the row with the lowest id and
        // reassign all resources pointing at duplicate rows to the kept row.
        $duplicates = DB::table('publishers')
            ->select('name', DB::raw('MIN(id) as keep_id'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            // Reassign resources from duplicate publisher rows to the kept row
            DB::table('resources')
                ->whereIn('publisher_id', function ($query) use ($dup) {
                    $query->select('id')
                        ->from('publishers')
                        ->where('name', $dup->name)
                        ->where('id', '!=', $dup->keep_id);
                })
                ->update(['publisher_id' => $dup->keep_id]);

            // Delete the duplicate rows
            DB::table('publishers')
                ->where('name', $dup->name)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        Schema::table('publishers', function (Blueprint $table): void {
            $table->unique('name');
        });
    }
};
