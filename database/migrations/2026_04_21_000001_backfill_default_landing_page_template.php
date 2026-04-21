<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $slug = 'default_gfz';
        $preferredName = 'Default GFZ Data Services';
        $rightColumnOrder = json_encode([
            'descriptions',
            'creators',
            'contributors',
            'funders',
            'keywords',
            'metadata_download',
            'location',
        ], JSON_THROW_ON_ERROR);
        $leftColumnOrder = json_encode([
            'files',
            'contact',
            'model_description',
            'related_work',
        ], JSON_THROW_ON_ERROR);

        $template = DB::table('landing_page_templates')
            ->where('slug', $slug)
            ->first();

        if ($template === null) {
            $name = $this->resolveUniqueDefaultTemplateName($preferredName);

            try {
                DB::table('landing_page_templates')->insert([
                    'name' => $name,
                    'slug' => $slug,
                    'is_default' => true,
                    'logo_path' => null,
                    'logo_filename' => null,
                    'right_column_order' => $rightColumnOrder,
                    'left_column_order' => $leftColumnOrder,
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                // If another process created the same slug in between, continue idempotently.
            }

            $template = DB::table('landing_page_templates')
                ->where('slug', $slug)
                ->first();
        }

        if ($template === null) {
            return;
        }

        DB::table('landing_page_templates')
            ->where('id', $template->id)
            ->update([
                'is_default' => true,
                'right_column_order' => $rightColumnOrder,
                'left_column_order' => $leftColumnOrder,
                'updated_at' => now(),
            ]);

        DB::table('landing_page_templates')
            ->where('is_default', true)
            ->where('id', '!=', $template->id)
            ->update([
                'is_default' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty: this is a data safety backfill.
    }

    private function resolveUniqueDefaultTemplateName(string $preferredName): string
    {
        $exists = DB::table('landing_page_templates')->where('name', $preferredName)->exists();

        if (! $exists) {
            return $preferredName;
        }

        for ($index = 2; $index <= 1000; $index++) {
            $candidate = $preferredName . ' ' . $index;
            if (! DB::table('landing_page_templates')->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $preferredName . ' ' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000' || $sqlState === '23505' || $driverCode === '1062' || $driverCode === '19';
    }
};
