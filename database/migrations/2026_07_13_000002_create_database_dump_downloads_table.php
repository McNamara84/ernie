<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'database_dump_downloads';

    private const EXPORT_FOREIGN = 'db_dump_downloads_export_fk';

    private const USER_FOREIGN = 'db_dump_downloads_user_fk';

    // Laravel's generated name is 67 characters long, exceeding MySQL's
    // 64-character identifier limit after the table itself has been created.
    private const AUDIT_INDEX = 'db_dump_downloads_export_downloaded_idx';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            $this->repairExistingTable();

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('database_dump_export_id');
            $table->foreignId('user_id');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('downloaded_at')->useCurrent();
            $table->timestamps();

            $table->foreign('database_dump_export_id', self::EXPORT_FOREIGN)
                ->references('id')
                ->on('database_dump_exports')
                ->cascadeOnDelete();
            $table->foreign('user_id', self::USER_FOREIGN)
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->index(
                ['database_dump_export_id', 'downloaded_at'],
                self::AUDIT_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function repairExistingTable(): void
    {
        $requiredColumns = [
            'id',
            'database_dump_export_id',
            'user_id',
            'ip_address',
            'user_agent',
            'downloaded_at',
            'created_at',
            'updated_at',
        ];
        $missingColumns = array_values(array_filter(
            $requiredColumns,
            fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column),
        ));

        if ($missingColumns !== []) {
            throw new RuntimeException(sprintf(
                'Cannot repair %s: required column(s) are missing: %s.',
                self::TABLE,
                implode(', ', $missingColumns),
            ));
        }

        $this->ensureForeignKey(
            column: 'database_dump_export_id',
            foreignTable: 'database_dump_exports',
            foreignName: self::EXPORT_FOREIGN,
        );
        $this->ensureForeignKey(
            column: 'user_id',
            foreignTable: 'users',
            foreignName: self::USER_FOREIGN,
        );

        if (! $this->hasIndex(['database_dump_export_id', 'downloaded_at'])) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['database_dump_export_id', 'downloaded_at'],
                    self::AUDIT_INDEX,
                );
            });
        }
    }

    private function ensureForeignKey(string $column, string $foreignTable, string $foreignName): void
    {
        if ($this->hasForeignKey($column, $foreignTable)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            throw new RuntimeException(sprintf(
                'Cannot repair %s.%s foreign key on SQLite.',
                self::TABLE,
                $column,
            ));
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $foreignTable, $foreignName): void {
            $table->foreign($column, $foreignName)
                ->references('id')
                ->on($foreignTable)
                ->cascadeOnDelete();
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndex(array $columns): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => array_values($index['columns'] ?? []) === $columns);
    }

    private function hasForeignKey(string $column, string $foreignTable): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'] ?? [], true)
                && ($foreignKey['foreign_table'] ?? null) === $foreignTable);
    }
};
