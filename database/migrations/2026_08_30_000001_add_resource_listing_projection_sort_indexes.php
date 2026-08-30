<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const INDEXES = [
        'rlp_id_sort_idx' => ['is_igsn', 'resource_id'],
        'rlp_doi_sort_idx' => ['is_igsn', 'sort_doi', 'resource_id'],
        'rlp_title_sort_idx' => ['is_igsn', 'main_title_sort', 'resource_id'],
        'rlp_creator_sort_idx' => ['is_igsn', 'first_creator_sort', 'resource_id'],
        'rlp_resource_type_sort_idx' => ['is_igsn', 'resource_type_sort', 'resource_id'],
        'rlp_curator_name_sort_idx' => ['is_igsn', 'curator_name', 'resource_id'],
        'rlp_status_rank_sort_idx' => ['is_igsn', 'workflow_status_rank', 'resource_id'],
        'rlp_year_sort_idx' => ['is_igsn', 'sort_year', 'resource_id'],
        'rlp_created_sort_idx' => ['is_igsn', 'created_sort', 'resource_id'],
        'rlp_default_sort_idx' => ['is_igsn', 'updated_sort', 'resource_id'],
    ];

    private const BASELINE_INDEX = 'rlp_default_sort_idx';

    public function up(): void
    {
        if (! Schema::hasTable('resource_listing_projections')) {
            return;
        }

        if (! Schema::hasColumn('resource_listing_projections', 'main_title_sort')) {
            Schema::table('resource_listing_projections', function (Blueprint $table): void {
                $table->string('main_title_sort', 512)->default('')->after('main_title');
            });

            DB::table('resource_listing_projections')->update([
                'main_title_sort' => new Expression('SUBSTR(main_title, 1, 512)'),
            ]);
        }

        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex('resource_listing_projections', $name)) {
                continue;
            }

            Schema::table('resource_listing_projections', function (Blueprint $table) use ($columns, $name): void {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('resource_listing_projections')) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            // The default-sort index belongs to the original table definition and
            // must remain available when only this follow-up migration is rolled back.
            if ($name === self::BASELINE_INDEX) {
                continue;
            }

            if (! Schema::hasIndex('resource_listing_projections', $name)) {
                continue;
            }

            Schema::table('resource_listing_projections', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }

        if (Schema::hasColumn('resource_listing_projections', 'main_title_sort')) {
            Schema::table('resource_listing_projections', function (Blueprint $table): void {
                $table->dropColumn('main_title_sort');
            });
        }
    }
};
