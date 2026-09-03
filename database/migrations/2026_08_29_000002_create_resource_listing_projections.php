<?php

declare(strict_types=1);

use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_listing_projections', function (Blueprint $table): void {
            $table->foreignId('resource_id')
                ->primary()
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->boolean('is_igsn')->default(false);
            $table->boolean('has_spdx_license')->default(false);
            $table->string('workflow_status', 16);
            $table->unsignedTinyInteger('workflow_status_rank');
            $table->boolean('is_dashboard_draft');
            $table->foreignId('resource_type_id')->nullable();
            $table->string('resource_type_slug')->nullable();
            $table->string('resource_type_sort')->default('');
            $table->foreignId('datacenter_id')->nullable();
            $table->foreignId('curator_user_id')->nullable();
            $table->string('curator_name')->default('');
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->unsignedSmallInteger('sort_year')->default(0);
            $table->string('sort_doi')->default('');
            $table->string('main_title', 1000)->default('');
            // The complete title remains available for display. Cursor sorting uses
            // a bounded prefix so MySQL/MariaDB can index it under utf8mb4.
            $table->string('main_title_sort', 512)->default('');
            $table->string('first_creator_sort')->default('');
            $table->string('created_sort', 40);
            $table->string('updated_sort', 40);
            $table->text('search_text');
            $table->timestamps();

            $table->index(['is_igsn', 'resource_id'], 'rlp_id_sort_idx');
            $table->index(['is_igsn', 'sort_doi', 'resource_id'], 'rlp_doi_sort_idx');
            $table->index(['is_igsn', 'main_title_sort', 'resource_id'], 'rlp_title_sort_idx');
            $table->index(['is_igsn', 'first_creator_sort', 'resource_id'], 'rlp_creator_sort_idx');
            $table->index(['is_igsn', 'resource_type_sort', 'resource_id'], 'rlp_resource_type_sort_idx');
            $table->index(['is_igsn', 'curator_name', 'resource_id'], 'rlp_curator_name_sort_idx');
            $table->index(['is_igsn', 'workflow_status_rank', 'resource_id'], 'rlp_status_rank_sort_idx');
            $table->index(['is_igsn', 'sort_year', 'resource_id'], 'rlp_year_sort_idx');
            $table->index(['is_igsn', 'created_sort', 'resource_id'], 'rlp_created_sort_idx');
            $table->index(['is_igsn', 'updated_sort', 'resource_id'], 'rlp_default_sort_idx');
            $table->index(['is_igsn', 'workflow_status', 'updated_sort', 'resource_id'], 'rlp_status_idx');
            $table->index(['is_igsn', 'is_dashboard_draft'], 'rlp_dashboard_draft_idx');
            $table->index(['is_igsn', 'resource_type_id', 'updated_sort', 'resource_id'], 'rlp_type_idx');
            $table->index(['is_igsn', 'datacenter_id', 'updated_sort', 'resource_id'], 'rlp_datacenter_idx');
            $table->index(['is_igsn', 'curator_user_id', 'updated_sort', 'resource_id'], 'rlp_curator_idx');
            $table->index(['is_igsn', 'publication_year', 'updated_sort', 'resource_id'], 'rlp_year_idx');
            $table->index(['is_igsn', 'has_spdx_license', 'updated_sort', 'resource_id'], 'rlp_spdx_license_idx');
        });

        // This is an integrated rollout: all existing rows are projected before
        // the new list query becomes available to application requests.
        app(ResourceListingProjectorService::class)->rebuildAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_listing_projections');
    }
};
