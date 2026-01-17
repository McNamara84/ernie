<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add parent-child hierarchy support to igsn_metadata table.
 *
 * IGSNs have hierarchical relationships:
 * - Borehole (parent)
 *   - Core Section (child of borehole, parent of samples)
 *     - Sample (child of core section)
 *
 * This uses the Adjacency List pattern where each IGSN can reference
 * its parent via parent_resource_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->foreignId('parent_resource_id')
                ->nullable()
                ->after('resource_id')
                ->constrained('resources')
                ->nullOnDelete();

            $table->index('parent_resource_id');
        });
    }
};
