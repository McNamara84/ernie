<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_template_date_type_exclusions', function (Blueprint $table): void {
            $table->unsignedBigInteger('landing_page_template_id');
            $table->unsignedBigInteger('date_type_id');

            $table->primary(
                ['landing_page_template_id', 'date_type_id'],
                'lpt_date_type_exclusions_pk',
            );
            $table->foreign('landing_page_template_id', 'lpt_date_type_exclusions_template_fk')
                ->references('id')
                ->on('landing_page_templates')
                ->cascadeOnDelete();
            $table->foreign('date_type_id', 'lpt_date_type_exclusions_type_fk')
                ->references('id')
                ->on('date_types')
                ->cascadeOnDelete();
        });

        Schema::create('landing_page_template_relation_type_exclusions', function (Blueprint $table): void {
            $table->unsignedBigInteger('landing_page_template_id');
            $table->unsignedBigInteger('relation_type_id');

            $table->primary(
                ['landing_page_template_id', 'relation_type_id'],
                'lpt_relation_type_exclusions_pk',
            );
            $table->foreign('landing_page_template_id', 'lpt_relation_type_exclusions_template_fk')
                ->references('id')
                ->on('landing_page_templates')
                ->cascadeOnDelete();
            $table->foreign('relation_type_id', 'lpt_relation_type_exclusions_type_fk')
                ->references('id')
                ->on('relation_types')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_template_relation_type_exclusions');
        Schema::dropIfExists('landing_page_template_date_type_exclusions');
    }
};
