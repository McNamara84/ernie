<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make resource_type_id nullable to support draft resources (Issue #548).
 *
 * Draft resources may be saved without a resource type selected.
 * The original schema had resource_type_id as NOT NULL with restrictOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->foreignId('resource_type_id')
                ->nullable()
                ->change();
        });
    }
};
