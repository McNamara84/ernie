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
     * agree on PascalCase via `ResourceType::nameToDataciteResourceTypeGeneral()`;
     * this migration backfills any rows persisted by the kebab-case parser.
     */
    public function up(): void
    {
        $rows = DB::table('related_items')
            ->select(['id', 'related_item_type'])
            ->get();

        foreach ($rows as $row) {
            $current = (string) $row->related_item_type;
            $canonical = Str::studly($current);

            if ($canonical !== '' && $canonical !== $current) {
                DB::table('related_items')
                    ->where('id', $row->id)
                    ->update(['related_item_type' => $canonical]);
            }
        }
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
