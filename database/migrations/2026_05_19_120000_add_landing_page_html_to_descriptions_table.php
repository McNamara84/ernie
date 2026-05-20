<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('descriptions', function (Blueprint $table): void {
            $table->longText('landing_page_html')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('descriptions', function (Blueprint $table): void {
            $table->dropColumn('landing_page_html');
        });
    }
};