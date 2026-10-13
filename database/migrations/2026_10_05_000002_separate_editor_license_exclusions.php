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
        Schema::table('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->string('editor', 16)->default('ernie');
            $table->unique(['right_id', 'resource_type_id', 'editor'], 'unique_editor_exclusion');
        });
        Schema::table('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->dropUnique('unique_exclusion');
        });

        DB::table('right_resource_type_exclusions')->where('editor', 'ernie')
            ->orderBy('id')->chunkById(500, function ($rows): void {
                $copies = [];
                foreach ($rows as $row) {
                    foreach (['elmo', 'elmo-msl'] as $editor) {
                        $copies[] = [
                            'right_id' => $row->right_id,
                            'resource_type_id' => $row->resource_type_id,
                            'editor' => $editor,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }
                }
                DB::table('right_resource_type_exclusions')->insert($copies);
            });
    }

    public function down(): void
    {
        $divergent = DB::table('right_resource_type_exclusions')
            ->select('right_id', 'resource_type_id')
            ->groupBy('right_id', 'resource_type_id')
            ->havingRaw('COUNT(DISTINCT editor) <> 3')->exists();

        if ($divergent) {
            throw new RuntimeException('Cannot roll back independently configured editor license exclusions.');
        }

        DB::table('right_resource_type_exclusions')->where('editor', '!=', 'ernie')->delete();
        Schema::table('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->unique(['right_id', 'resource_type_id'], 'unique_exclusion');
        });
        Schema::table('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->dropUnique('unique_editor_exclusion');
            $table->dropColumn('editor');
        });
    }
};
