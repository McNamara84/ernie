<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'resource_rights';

    private const TEMP_TABLE = 'resource_rights_with_raw_fields';

    private const UNIQUE_INDEX = 'resource_rights_resource_id_rights_id_unique';

    private const RESOURCE_SOURCE_INDEX = 'resource_rights_resource_source_idx';

    /**
     * The old table was a pure many-to-many pivot. For SPDX review workflows we
     * need one row per imported rights statement, even while that statement is
     * still unresolved. The nullable rights_id keeps the original statement
     * exportable and lets a later assistant acceptance link it to the shared
     * SPDX rights catalog without replacing curator-entered catalog data.
     */
    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'rights_text')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeRawFields: true);

            return;
        }

        $this->dropForeignKeyIfExists('rights_id');

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('rights_id')->nullable()->change();

            // Raw DataCite fields are kept beside the optional catalog link so
            // imports can remain faithful before a curator accepts a suggestion.
            $table->text('rights_text')->nullable()->after('rights_id');
            $table->string('rights_uri', 512)->nullable()->after('rights_text');
            $table->string('rights_identifier')->nullable()->after('rights_uri');
            $table->string('rights_identifier_scheme', 100)->nullable()->after('rights_identifier');
            $table->string('scheme_uri', 512)->nullable()->after('rights_identifier_scheme');
            $table->string('language', 10)->nullable()->after('scheme_uri');
            $table->string('source', 100)->nullable()->after('language');
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            // If a catalog right is ever removed, the imported statement should
            // stay on the resource and simply become unresolved again.
            $table->foreign('rights_id')
                ->references('id')
                ->on('rights')
                ->nullOnDelete();

            $table->index(['resource_id', 'source'], self::RESOURCE_SOURCE_INDEX);
        });
    }

    /**
     * Roll the table back to its previous pure-pivot shape.
     *
     * Rows without a catalog link cannot fit into the old schema, so rollback
     * intentionally drops those unresolved imported statements.
     */
    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'rights_text')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(includeRawFields: false);

            return;
        }

        DB::table(self::TABLE)->whereNull('rights_id')->delete();

        $this->dropForeignKeyIfExists('rights_id');

        if (Schema::hasIndex(self::TABLE, self::RESOURCE_SOURCE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::RESOURCE_SOURCE_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn([
                'rights_text',
                'rights_uri',
                'rights_identifier',
                'rights_identifier_scheme',
                'scheme_uri',
                'language',
                'source',
            ]);

            $table->unsignedBigInteger('rights_id')->nullable(false)->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreign('rights_id')
                ->references('id')
                ->on('rights')
                ->cascadeOnDelete();

        });
    }

    private function dropForeignKeyIfExists(string $column): void
    {
        $constraint = $this->foreignKeyName($column);

        if ($constraint === null) {
            return;
        }

        $this->dropForeignKeyNamed($constraint);
    }

    private function dropForeignKeyNamed(string $constraint): void
    {
        try {
            Schema::table(self::TABLE, function (Blueprint $table) use ($constraint): void {
                $table->dropForeign($constraint);
            });
        } catch (QueryException $exception) {
            if (! $this->isMissingForeignKeyDropError($exception)) {
                throw $exception;
            }
        }
    }

    private function foreignKeyName(string $column): ?string
    {
        $result = DB::selectOne(
            <<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
            SQL,
            [self::TABLE, $column],
        );

        return is_string($result->constraint_name ?? null)
            ? $result->constraint_name
            : null;
    }

    private function isMissingForeignKeyDropError(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $driverCode === '1091'
            && in_array($sqlState, ['42000', '42S02'], true)
            && str_contains($exception->getMessage(), "Can't DROP");
    }

    /**
     * SQLite cannot reliably alter foreign-key nullability in-place. Rebuilding
     * the table keeps the test database deterministic and mirrors the shape that
     * MySQL receives above.
     */
    private function rebuildSqliteTable(bool $includeRawFields): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists(self::TEMP_TABLE);

        Schema::create(self::TEMP_TABLE, function (Blueprint $table) use ($includeRawFields): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();

            if ($includeRawFields) {
                $table->foreignId('rights_id')
                    ->nullable()
                    ->constrained('rights')
                    ->nullOnDelete();

                $table->text('rights_text')->nullable();
                $table->string('rights_uri', 512)->nullable();
                $table->string('rights_identifier')->nullable();
                $table->string('rights_identifier_scheme', 100)->nullable();
                $table->string('scheme_uri', 512)->nullable();
                $table->string('language', 10)->nullable();
                $table->string('source', 100)->nullable();
            } else {
                $table->foreignId('rights_id')
                    ->constrained('rights')
                    ->cascadeOnDelete();
            }

            $table->timestamps();
        });

        if ($includeRawFields) {
            DB::statement(sprintf(
                'INSERT INTO %s (id, resource_id, rights_id, created_at, updated_at) SELECT id, resource_id, rights_id, created_at, updated_at FROM %s',
                self::TEMP_TABLE,
                self::TABLE,
            ));
        } else {
            DB::statement(sprintf(
                'INSERT INTO %s (id, resource_id, rights_id, created_at, updated_at) SELECT id, resource_id, rights_id, created_at, updated_at FROM %s WHERE rights_id IS NOT NULL',
                self::TEMP_TABLE,
                self::TABLE,
            ));
        }

        Schema::drop(self::TABLE);
        Schema::rename(self::TEMP_TABLE, self::TABLE);

        Schema::table(self::TABLE, function (Blueprint $table) use ($includeRawFields): void {
            // SQLite index names are database-wide, not table-scoped. The old
            // pivot index still exists while the temporary table is being
            // created, so we add indexes only after the old table has been
            // dropped and the rebuilt table has taken the final name.
            $table->unique(['resource_id', 'rights_id'], self::UNIQUE_INDEX);

            if ($includeRawFields) {
                $table->index(['resource_id', 'source'], self::RESOURCE_SOURCE_INDEX);
            }
        });

        Schema::enableForeignKeyConstraints();
    }
};
