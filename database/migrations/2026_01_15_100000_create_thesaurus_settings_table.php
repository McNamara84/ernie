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
        Schema::create('thesaurus_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique(); // 'science_keywords', 'platforms', 'instruments'
            $table->string('display_name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->timestamps();
        });
    }
};
