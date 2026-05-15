<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->text('citation_label')->nullable()->after('relation_type_information');
        });

        DB::table('related_identifiers')
            ->whereNull('citation_label')
            ->whereNotNull('identifier')
            ->where('identifier', '!=', '')
            ->update([
                'citation_label' => DB::raw('identifier'),
            ]);
    }

    public function down(): void
    {
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->dropColumn('citation_label');
        });
    }
};