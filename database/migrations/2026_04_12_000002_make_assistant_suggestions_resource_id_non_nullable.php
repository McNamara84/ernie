<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make resource_id non-nullable in assistant_suggestions.
 *
 * All assistant suggestions are tied to a specific resource. Making this
 * column required enforces that constraint at the database level and
 * aligns with the TypeScript type (BaseSuggestionItem.resource_id: number)
 * and the UI grouping logic which groups suggestions by resource.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_id')->nullable()->change();
        });
    }
};
