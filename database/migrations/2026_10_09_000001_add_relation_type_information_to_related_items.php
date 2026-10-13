<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('related_items', function (Blueprint $table): void {
            $table->text('relation_type_information')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('related_items', function (Blueprint $table): void {
            $table->dropColumn('relation_type_information');
        });
    }
};
