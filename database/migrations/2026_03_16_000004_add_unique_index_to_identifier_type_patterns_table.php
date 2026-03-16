<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identifier_type_patterns', function (Blueprint $table): void {
            $table->unique(['identifier_type_id', 'type', 'pattern'], 'identifier_type_patterns_unique');
        });
    }

    public function down(): void
    {
        Schema::table('identifier_type_patterns', function (Blueprint $table): void {
            $table->dropUnique('identifier_type_patterns_unique');
        });
    }
};
