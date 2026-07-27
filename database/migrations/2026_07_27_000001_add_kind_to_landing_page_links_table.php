<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->string('kind', 20)->default('related')->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
