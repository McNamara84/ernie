<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand the legacy `descriptions` section key into individual description-type
 * modules for landing page templates.
 *
 * Existing right-column orders stored a single `descriptions` slot which
 * rendered Abstract and optionally Methods inside one shared card. The new
 * landing-page renderer supports independent template ordering for every
 * supported DataCite description type, so historic orders must be rewritten.
 *
 * Migration strategy:
 * - keep the relative ordering intent of all existing non-description keys
 * - replace `descriptions` in-place with the new description-type keys
 * - append any missing keys from the new canonical set
 * - keep `location` as a standalone card before or after the shared metadata
 *   card, never in the middle of the expanded metadata-module list
 *
 * Canonical keys are intentionally inlined here so the migration remains a
 * stable historical artifact independent of future model changes.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const DESCRIPTION_SECTIONS = [
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
    ];

    /** @var list<string> */
    private const RIGHT_METADATA_CANONICAL = [
        ...self::DESCRIPTION_SECTIONS,
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
    ];

    public function up(): void
    {
        DB::table('landing_page_templates')
            ->select(['id', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $right = $this->normalizeRightOrder($this->decodeOrder($row->right_column_order));

                DB::table('landing_page_templates')
                    ->where('id', $row->id)
                    ->update([
                        'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // No-op. Reconstructing the historic single `descriptions` slot would
        // destroy ordering information introduced by the new expanded modules.
    }

    /**
     * @return list<string>
     */
    private function decodeOrder(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param  list<string>  $stored
     * @return list<string>
     */
    private function normalizeRightOrder(array $stored): array
    {
        $locationBeforeMetadata = $this->locationAppearsBeforeMetadata($stored);
        $cleanedMetadata = [];

        foreach ($stored as $key) {
            if ($key === 'location') {
                continue;
            }

            if ($key === 'descriptions') {
                foreach (self::DESCRIPTION_SECTIONS as $descriptionKey) {
                    if (! in_array($descriptionKey, $cleanedMetadata, true)) {
                        $cleanedMetadata[] = $descriptionKey;
                    }
                }

                continue;
            }

            if (! in_array($key, self::RIGHT_METADATA_CANONICAL, true)) {
                continue;
            }

            if (! in_array($key, $cleanedMetadata, true)) {
                $cleanedMetadata[] = $key;
            }
        }

        foreach (self::RIGHT_METADATA_CANONICAL as $canonicalKey) {
            if (! in_array($canonicalKey, $cleanedMetadata, true)) {
                $cleanedMetadata[] = $canonicalKey;
            }
        }

        if ($locationBeforeMetadata) {
            return ['location', ...$cleanedMetadata];
        }

        return [...$cleanedMetadata, 'location'];
    }

    /**
     * @param  list<string>  $stored
     */
    private function locationAppearsBeforeMetadata(array $stored): bool
    {
        foreach ($stored as $key) {
            if ($key === 'location') {
                return true;
            }

            if ($key === 'descriptions' || in_array($key, self::RIGHT_METADATA_CANONICAL, true)) {
                return false;
            }
        }

        return false;
    }
};