<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `right_column_order` and `left_column_order` on all existing
 * landing page templates so they contain the full canonical set of section
 * keys defined by `LandingPageTemplate::RIGHT_COLUMN_SECTIONS` /
 * `LEFT_COLUMN_SECTIONS`.
 *
 * Templates created before new section keys (e.g. `general`, `acquisition`)
 * were added would otherwise become unsavable because the validator requires
 * the order array to contain exactly the canonical set.
 *
 * Strategy: keep the existing ordering, drop any unknown keys, then append
 * canonical keys that are missing. This preserves user customization while
 * making old rows valid again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rightCanonical = LandingPageTemplate::RIGHT_COLUMN_SECTIONS;
        $leftCanonical = LandingPageTemplate::LEFT_COLUMN_SECTIONS;

        DB::table('landing_page_templates')
            ->select(['id', 'right_column_order', 'left_column_order'])
            ->orderBy('id')
            ->each(function (object $row) use ($rightCanonical, $leftCanonical): void {
                $right = $this->normalize($this->decodeOrder($row->right_column_order), $rightCanonical);
                $left = $this->normalize($this->decodeOrder($row->left_column_order), $leftCanonical);

                DB::table('landing_page_templates')
                    ->where('id', $row->id)
                    ->update([
                        'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
                        'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // No-op: the canonical ordering is the authoritative source. We do not
        // attempt to restore historic shorter orderings since the original keys
        // are not recoverable from canonical state.
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
     * Drop unknown keys, deduplicate, then append missing canonical keys.
     *
     * @param  list<string>  $stored
     * @param  list<string>  $canonical
     * @return list<string>
     */
    private function normalize(array $stored, array $canonical): array
    {
        $canonicalSet = array_flip($canonical);

        $cleaned = [];
        foreach ($stored as $key) {
            if (! isset($canonicalSet[$key])) {
                continue;
            }
            if (in_array($key, $cleaned, true)) {
                continue;
            }
            $cleaned[] = $key;
        }

        foreach ($canonical as $key) {
            if (! in_array($key, $cleaned, true)) {
                $cleaned[] = $key;
            }
        }

        return $cleaned;
    }
};
