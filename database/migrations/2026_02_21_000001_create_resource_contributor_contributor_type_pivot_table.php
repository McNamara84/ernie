<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create a pivot table for multiple contributor types per resource contributor,
     * migrate existing data, and drop the old single-FK column.
     *
     * This fixes Issue #539: Contributor roles were not properly saved because
     * the schema only supported a single contributor type per contributor.
     */
    public function up(): void
    {
        // 1. Create the pivot table (guard against re-runs during RefreshDatabase)
        if (! Schema::hasTable('resource_contributor_contributor_type')) {
            Schema::create('resource_contributor_contributor_type', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('resource_contributor_id');
                $table->unsignedBigInteger('contributor_type_id');
                $table->timestamps();

                $table->foreign('resource_contributor_id', 'rc_ct_contributor_fk')
                    ->references('id')
                    ->on('resource_contributors')
                    ->cascadeOnDelete();

                $table->foreign('contributor_type_id', 'rc_ct_type_fk')
                    ->references('id')
                    ->on('contributor_types')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unique(
                    ['resource_contributor_id', 'contributor_type_id'],
                    'rc_ct_unique'
                );
            });
        }

        // 2. Migrate existing data from the old single-FK column into the pivot table
        if (Schema::hasColumn('resource_contributors', 'contributor_type_id')) {
            $now = now();

            DB::table('resource_contributors')
                ->whereNotNull('contributor_type_id')
                ->select(['id', 'contributor_type_id'])
                ->chunkById(500, function ($rows) use ($now): void {
                $inserts = [];
                foreach ($rows as $row) {
                    $inserts[] = [
                        'resource_contributor_id' => $row->id,
                        'contributor_type_id' => $row->contributor_type_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('resource_contributor_contributor_type')->insertOrIgnore($inserts);
            });
        }

        // 3. Drop the old foreign key and column (only if it still exists)
        if (Schema::hasColumn('resource_contributors', 'contributor_type_id')) {
            Schema::table('resource_contributors', function (Blueprint $table): void {
                $table->dropForeign(['contributor_type_id']);
                $table->dropColumn('contributor_type_id');
            });
        }
    }
};
