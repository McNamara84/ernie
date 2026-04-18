<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('landing_pages', 'landing_page_template_id')) {
            Schema::table('landing_pages', function (Blueprint $table) {
                $table->foreignId('landing_page_template_id')
                    ->nullable()
                    ->after('template')
                    ->constrained('landing_page_templates')
                    ->nullOnDelete();
            });

            return;
        }

        // Column exists (from partial migration or hotfix) – ensure FK constraint is present.
        $hasFk = collect(Schema::getForeignKeys('landing_pages'))
            ->contains(fn (array $fk): bool => in_array('landing_page_template_id', $fk['columns']));

        if (! $hasFk) {
            Schema::table('landing_pages', function (Blueprint $table) {
                $table->foreign('landing_page_template_id')
                    ->references('id')
                    ->on('landing_page_templates')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('landing_pages', 'landing_page_template_id')) {
            return;
        }

        $fk = collect(Schema::getForeignKeys('landing_pages'))
            ->first(fn (array $fk): bool => in_array('landing_page_template_id', $fk['columns']));
        $fkName = $fk['name'] ?? null;

        Schema::table('landing_pages', function (Blueprint $table) use ($fkName) {
            if ($fkName !== null) {
                $table->dropForeign($fkName);
            }

            $table->dropColumn('landing_page_template_id');
        });
    }
};
