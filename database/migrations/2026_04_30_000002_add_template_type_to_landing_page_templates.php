<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `template_type` column to `landing_page_templates` so that the
 * application can distinguish between resource (DOI) and IGSN templates.
 *
 * The IGSN template uses the dedicated `default_gfz_igsn` Inertia page
 * component which renders IGSN-specific modules (General, Acquisition) and
 * never displays the Files module. By tagging templates with a type, users
 * can clone and customize a Default IGSN template the same way they already
 * clone the Default GFZ template for resources.
 *
 * Also seeds the immutable Default GFZ IGSN template row.
 */
return new class extends Migration
{
    private const RESOURCE = 'resource';

    private const IGSN = 'igsn';

    public function up(): void
    {
        if (! Schema::hasColumn('landing_page_templates', 'template_type')) {
            Schema::table('landing_page_templates', function (Blueprint $table): void {
                $table->string('template_type', 32)
                    ->default(self::RESOURCE)
                    ->after('is_default')
                    ->index();
            });
        }

        // Backfill the existing default template (and any clones of it) to 'resource'.
        DB::table('landing_page_templates')
            ->whereNull('template_type')
            ->orWhere('template_type', '')
            ->update(['template_type' => self::RESOURCE]);

        // Seed the Default GFZ IGSN template if it does not yet exist.
        $existing = DB::table('landing_page_templates')->where('slug', 'default_gfz_igsn')->first();

        if ($existing === null) {
            $name = $this->resolveUniqueName('Default GFZ IGSN');

            DB::table('landing_page_templates')->insert([
                'name' => $name,
                'slug' => 'default_gfz_igsn',
                'is_default' => true,
                'template_type' => self::IGSN,
                'logo_path' => null,
                'logo_filename' => null,
                'right_column_order' => json_encode([
                    'descriptions',
                    'creators',
                    'contributors',
                    'funders',
                    'keywords',
                    'metadata_download',
                    'location',
                ], JSON_THROW_ON_ERROR),
                'left_column_order' => json_encode([
                    'files',
                    'general',
                    'acquisition',
                    'contact',
                    'model_description',
                    'related_work',
                ], JSON_THROW_ON_ERROR),
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('landing_page_templates')
                ->where('id', $existing->id)
                ->update([
                    'template_type' => self::IGSN,
                    'is_default' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('landing_page_templates')->where('slug', 'default_gfz_igsn')->delete();

        if (Schema::hasColumn('landing_page_templates', 'template_type')) {
            Schema::table('landing_page_templates', function (Blueprint $table): void {
                $table->dropIndex(['template_type']);
                $table->dropColumn('template_type');
            });
        }
    }

    private function resolveUniqueName(string $preferred): string
    {
        if (! DB::table('landing_page_templates')->where('name', $preferred)->exists()) {
            return $preferred;
        }

        for ($i = 2; $i <= 1000; $i++) {
            $candidate = $preferred.' '.$i;
            if (! DB::table('landing_page_templates')->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $preferred.' '.bin2hex(random_bytes(3));
    }
};
