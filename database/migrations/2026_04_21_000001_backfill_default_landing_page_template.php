<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultTemplate = LandingPageTemplate::query()->where('is_default', true)->first();

        if ($defaultTemplate !== null) {
            return;
        }

        $template = LandingPageTemplate::query()->firstOrCreate(
            ['slug' => 'default_gfz'],
            [
                'name' => 'Default GFZ Data Services',
                'is_default' => true,
                'logo_path' => null,
                'logo_filename' => null,
                'right_column_order' => LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
                'left_column_order' => LandingPageTemplate::LEFT_COLUMN_SECTIONS,
                'created_by' => null,
            ]
        );

        if (! $template->is_default) {
            $template->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty: this is a data safety backfill.
    }
};
