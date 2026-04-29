<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Convert any kebab-case `related_items.related_item_type` rows back to
     * the canonical DataCite `resourceTypeGeneral` PascalCase enum value
     * (e.g. `journal-article` → `JournalArticle`).
     *
     * Background: an earlier iteration of `RelatedItemSectionParser` ran
     * imported XML values through `Str::kebab()` to satisfy a
     * `Rule::exists('resource_types', 'slug')` validation, while every other
     * code path (DataCite import, factories, exporters, `CitationFormatter`)
     * stored / consumed the PascalCase form. Schema-validated DataCite XML
     * exports of XML-imported items therefore failed against the
     * `resourceTypeGeneral` enum, and `CitationFormatter::containerTitle()`
     * silently skipped journal article container titles. Both code paths now
     * agree on PascalCase via `ResourceType::slugToDataciteResourceTypeGeneral()`
     * (and the matching instance helper `dataciteResourceTypeGeneral()`);
     * this migration backfills any rows persisted by the kebab-case parser.
     */
    public function up(): void
    {
        // Pre-filter at the database level so we only touch rows that
        // actually need conversion. The legacy parser produced kebab-case
        // (lowercase + hyphens), so a value containing a hyphen or starting
        // with a lowercase letter is the only candidate. Already-canonical
        // PascalCase rows (the vast majority) are skipped without ever being
        // hydrated into PHP, which keeps memory bounded and avoids issuing
        // no-op UPDATEs.
        DB::table('related_items')
            ->select(['id', 'related_item_type'])
            ->where(function ($query): void {
                $query->where('related_item_type', 'like', '%-%')
                    ->orWhereRaw('SUBSTR(related_item_type, 1, 1) = LOWER(SUBSTR(related_item_type, 1, 1))');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                /** @var array<string, list<int>> $idsByCanonical */
                $idsByCanonical = [];

                foreach ($rows as $row) {
                    $current = (string) $row->related_item_type;
                    $canonical = Str::studly($current);

                    if ($canonical === '' || $canonical === $current) {
                        continue;
                    }

                    $idsByCanonical[$canonical][] = (int) $row->id;
                }

                // One UPDATE per distinct target value, scoped to the matching
                // chunk ids. With ~10 distinct DataCite resourceTypeGeneral
                // values in practice this collapses thousands of per-row
                // UPDATEs into a handful of batched ones.
                foreach ($idsByCanonical as $canonical => $ids) {
                    DB::table('related_items')
                        ->whereIn('id', $ids)
                        ->update(['related_item_type' => $canonical]);
                }
            });
    }

    /**
     * Intentionally not reversible: the kebab-case form was never the
     * canonical representation, just a transient parser artefact.
     */
    public function down(): void
    {
        //
    }
};
