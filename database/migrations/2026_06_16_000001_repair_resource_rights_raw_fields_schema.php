<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'resource_rights';

    private const RIGHTS_FK = 'resource_rights_rights_id_foreign';

    private const RESOURCE_SOURCE_INDEX = 'resource_rights_resource_source_idx';

    /**
     * Repair Stage-like partial states from the raw rights migration.
     *
     * The original schema was a pure pivot with a required rights_id. The SPDX
     * assistant needs nullable rights_id plus raw imported DataCite fields. A
     * failed deploy can leave Stage with the migration recorded only partly, or
     * with a foreign key whose actual name is not Laravel's default. This
     * migration discovers the real constraint name before altering rights_id and
     * adds every raw column independently.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'rights_id')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->dropForeignKeyOnColumn('rights_id');

            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('rights_id')->nullable()->change();
            });
        }

        $this->addMissingRawColumns();

        if (DB::getDriverName() !== 'sqlite') {
            $this->ensureRightsForeignKey();
            $this->ensureResourceSourceIndex();
        }
    }

    public function down(): void
    {
        // This is a production schema repair. Rolling it back would reintroduce
        // the Stage failure mode and could drop unresolved imported rights
        // evidence, so the down path is intentionally non-destructive.
    }

    private function addMissingRawColumns(): void
    {
        $columns = [
            'rights_text' => fn (Blueprint $table): mixed => $table->text('rights_text')->nullable()->after('rights_id'),
            'rights_uri' => fn (Blueprint $table): mixed => $table->string('rights_uri', 512)->nullable()->after('rights_text'),
            'rights_identifier' => fn (Blueprint $table): mixed => $table->string('rights_identifier')->nullable()->after('rights_uri'),
            'rights_identifier_scheme' => fn (Blueprint $table): mixed => $table->string('rights_identifier_scheme', 100)->nullable()->after('rights_identifier'),
            'scheme_uri' => fn (Blueprint $table): mixed => $table->string('scheme_uri', 512)->nullable()->after('rights_identifier_scheme'),
            'language' => fn (Blueprint $table): mixed => $table->string('language', 10)->nullable()->after('scheme_uri'),
            'source' => fn (Blueprint $table): mixed => $table->string('source', 100)->nullable()->after('language'),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    private function ensureRightsForeignKey(): void
    {
        if ($this->foreignKeyName('rights_id') !== null) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreign('rights_id', self::RIGHTS_FK)
                ->references('id')
                ->on('rights')
                ->nullOnDelete();
        });
    }

    private function ensureResourceSourceIndex(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'resource_id') || ! Schema::hasColumn(self::TABLE, 'source')) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::RESOURCE_SOURCE_INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['resource_id', 'source'], self::RESOURCE_SOURCE_INDEX);
        });
    }

    private function dropForeignKeyOnColumn(string $column): void
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
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
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

        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];

            if (in_array($column, $columns, true)) {
                $name = $foreignKey['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    private function isMissingForeignKeyDropError(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '42000' && $driverCode === '1091';
    }
};
