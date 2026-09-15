<?php

declare(strict_types=1);

use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relation_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('slug');
        });

        Schema::table('identifier_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('slug');
        });

        foreach (RelationTypeSeeder::TYPES as $type) {
            DB::table('relation_types')
                ->where('slug', $type['slug'])
                ->update(['description' => $type['description']]);
        }

        foreach (IdentifierTypeSeeder::TYPES as $type) {
            DB::table('identifier_types')
                ->where('slug', $type['slug'])
                ->update(['description' => $type['description']]);
        }
    }

    public function down(): void
    {
        Schema::table('identifier_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('relation_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
