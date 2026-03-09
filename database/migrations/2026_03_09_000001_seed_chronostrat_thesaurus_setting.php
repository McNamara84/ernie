<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seed the chronostratigraphy thesaurus setting using firstOrCreate
     * so it's safe to run multiple times.
     */
    public function up(): void
    {
        ThesaurusSetting::firstOrCreate(
            ['type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
            [
                'display_name' => 'ICS Chronostratigraphy',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ThesaurusSetting::where('type', ThesaurusSetting::TYPE_CHRONOSTRAT)->delete();
    }
};
