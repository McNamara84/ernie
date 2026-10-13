<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oai_pmh_harvest_items', function (Blueprint $table): void {
            $table->foreignId('harvest_id')->constrained('oai_pmh_harvests')->cascadeOnDelete();
            $table->unsignedBigInteger('position');
            $table->string('kind', 8);
            // Deliberately no resource FK: deletion must not remove a snapshot position.
            $table->unsignedBigInteger('identity_id');
            $table->primary(['harvest_id', 'position']);
        });
        Schema::table('oai_pmh_harvests', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_count')->default(0);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                INSERT INTO oai_pmh_harvest_items (harvest_id, position, kind, identity_id)
                SELECT harvest.id, entry.ordinal - 1, entry.kind, entry.identity_id
                FROM oai_pmh_harvests AS harvest
                CROSS JOIN JSON_TABLE(harvest.items, '$[*]' COLUMNS (
                    ordinal FOR ORDINALITY,
                    kind VARCHAR(8) PATH '$.kind',
                    identity_id BIGINT PATH '$.id'
                )) AS entry
                SQL);
            DB::statement('UPDATE oai_pmh_harvests SET item_count = JSON_LENGTH(items)');
        } else {
            DB::statement(<<<'SQL'
                INSERT INTO oai_pmh_harvest_items (harvest_id, position, kind, identity_id)
                SELECT harvest.id, CAST(entry.key AS INTEGER),
                    json_extract(entry.value, '$.kind'), json_extract(entry.value, '$.id')
                FROM oai_pmh_harvests AS harvest
                CROSS JOIN json_each(harvest.items) AS entry
                SQL);
            DB::statement('UPDATE oai_pmh_harvests SET item_count = json_array_length(items)');
        }
        Schema::table('oai_pmh_harvests', function (Blueprint $table): void {
            $table->dropColumn('items');
        });
    }

    public function down(): void
    {
        Schema::table('oai_pmh_harvests', function (Blueprint $table): void {
            // A constant default avoids rebuilding the SQLite parent table,
            // which would cascade-delete still-valid resumption tokens.
            $table->json('items')->default(DB::getDriverName() === 'mysql' ? DB::raw("('[]')") : '[]');
        });
        DB::table('oai_pmh_harvests')->update(['items' => '[]']);

        // Restore the legacy array order inside the database, without PHP decoding.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE oai_pmh_harvests AS harvest
                JOIN (
                    SELECT harvest_id, position,
                        JSON_ARRAYAGG(JSON_OBJECT('kind', kind, 'id', identity_id)) OVER (
                            PARTITION BY harvest_id ORDER BY position
                            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                        ) AS items
                    FROM oai_pmh_harvest_items
                ) AS legacy ON legacy.harvest_id = harvest.id AND legacy.position = 0
                SET harvest.items = legacy.items
                SQL);
        } else {
            DB::statement(<<<'SQL'
                WITH legacy AS (
                    SELECT harvest_id, position,
                        json_group_array(json_object('kind', kind, 'id', identity_id)) OVER (
                            PARTITION BY harvest_id ORDER BY position
                            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                        ) AS items
                    FROM oai_pmh_harvest_items
                )
                UPDATE oai_pmh_harvests SET items = COALESCE((
                    SELECT legacy.items FROM legacy
                    WHERE legacy.harvest_id = oai_pmh_harvests.id AND legacy.position = 0
                ), '[]')
                SQL);
        }
        Schema::table('oai_pmh_harvests', function (Blueprint $table): void {
            $table->dropColumn('item_count');
        });
        Schema::dropIfExists('oai_pmh_harvest_items');
    }
};
