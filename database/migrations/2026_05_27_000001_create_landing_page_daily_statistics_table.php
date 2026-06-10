<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('landing_page_daily_statistics')) {
            $this->repairExistingTable();

            return;
        }

        $this->createTable();
    }

    private function createTable(): void
    {
        Schema::create('landing_page_daily_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')
                ->constrained('landing_pages')
                ->cascadeOnDelete();
            $table->date('statistic_date');
            $table->unsignedInteger('page_view_count')->default(0);
            $table->unsignedInteger('file_download_click_count')->default(0);
            $table->timestamps();

            $table->unique(['landing_page_id', 'statistic_date'], 'lp_daily_stats_page_date_unique');
            $table->index('statistic_date');
        });
    }

    private function repairExistingTable(): void
    {
        $this->ensurePrimaryIdColumn();
        $this->ensureColumns();
        $this->ensureIndexes();
        $this->ensureForeignKey();
    }

    private function ensurePrimaryIdColumn(): void
    {
        if (Schema::hasColumn('landing_page_daily_statistics', 'id')) {
            return;
        }

        if ($this->hasPrimaryIndex('landing_page_daily_statistics')) {
            throw new RuntimeException(
                'Cannot repair landing_page_daily_statistics: table has a primary key but no id column.'
            );
        }

        if ($this->isSqlite()) {
            throw new RuntimeException(
                'Cannot repair landing_page_daily_statistics on SQLite: adding an auto-incrementing id column to an existing table is unsupported.'
            );
        }

        $this->alterTableOrFail(
            'Cannot add landing_page_daily_statistics.id. Inspect the existing table schema before rerunning migrations.',
            function (Blueprint $table): void {
                $table->id()->first();
            }
        );
    }

    private function ensureColumns(): void
    {
        $missingRequiredColumns = array_filter([
            'landing_page_id' => ! Schema::hasColumn('landing_page_daily_statistics', 'landing_page_id'),
            'statistic_date' => ! Schema::hasColumn('landing_page_daily_statistics', 'statistic_date'),
        ]);

        if ($missingRequiredColumns !== [] && $this->tableHasRows('landing_page_daily_statistics')) {
            $columns = implode(', ', array_keys($missingRequiredColumns));

            throw new RuntimeException(
                "Cannot repair landing_page_daily_statistics: existing rows are missing required column(s): {$columns}."
            );
        }

        if ($missingRequiredColumns !== [] && $this->isSqlite()) {
            $columns = implode(', ', array_keys($missingRequiredColumns));

            throw new RuntimeException(
                "Cannot repair landing_page_daily_statistics on SQLite: adding required non-null column(s) is unsupported: {$columns}."
            );
        }

        $missingColumns = [
            'landing_page_id' => ! Schema::hasColumn('landing_page_daily_statistics', 'landing_page_id'),
            'statistic_date' => ! Schema::hasColumn('landing_page_daily_statistics', 'statistic_date'),
            'page_view_count' => ! Schema::hasColumn('landing_page_daily_statistics', 'page_view_count'),
            'file_download_click_count' => ! Schema::hasColumn('landing_page_daily_statistics', 'file_download_click_count'),
            'created_at' => ! Schema::hasColumn('landing_page_daily_statistics', 'created_at'),
            'updated_at' => ! Schema::hasColumn('landing_page_daily_statistics', 'updated_at'),
        ];

        if (! in_array(true, $missingColumns, true)) {
            return;
        }

        $this->alterTableOrFail(
            'Cannot repair landing_page_daily_statistics columns. Inspect the existing table schema before rerunning migrations.',
            function (Blueprint $table) use ($missingColumns): void {
                if ($missingColumns['landing_page_id']) {
                    $table->unsignedBigInteger('landing_page_id')->after('id');
                }

                if ($missingColumns['statistic_date']) {
                    $table->date('statistic_date')->after('landing_page_id');
                }

                if ($missingColumns['page_view_count']) {
                    $table->unsignedInteger('page_view_count')->default(0)->after('statistic_date');
                }

                if ($missingColumns['file_download_click_count']) {
                    $table->unsignedInteger('file_download_click_count')->default(0)->after('page_view_count');
                }

                if ($missingColumns['created_at']) {
                    $table->timestamp('created_at')->nullable()->after('file_download_click_count');
                }

                if ($missingColumns['updated_at']) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            }
        );
    }

    private function ensureIndexes(): void
    {
        if (! $this->hasIndex('landing_page_daily_statistics', ['landing_page_id', 'statistic_date'], unique: true)) {
            $this->alterTableOrFail(
                'Cannot add landing_page_daily_statistics unique index. Existing rows may contain duplicate landing_page_id/statistic_date pairs.',
                function (Blueprint $table): void {
                    $table->unique(['landing_page_id', 'statistic_date'], 'lp_daily_stats_page_date_unique');
                }
            );
        }

        if (! $this->hasIndex('landing_page_daily_statistics', ['statistic_date'])) {
            $this->alterTableOrFail(
                'Cannot add landing_page_daily_statistics.statistic_date index.',
                function (Blueprint $table): void {
                    $table->index('statistic_date');
                }
            );
        }
    }

    private function ensureForeignKey(): void
    {
        if ($this->isSqlite() || $this->hasForeignKey('landing_page_daily_statistics', 'landing_page_id', 'landing_pages')) {
            return;
        }

        $this->alterTableOrFail(
            'Cannot add landing_page_daily_statistics.landing_page_id foreign key. Existing rows may reference missing landing_pages.id values.',
            function (Blueprint $table): void {
                $table->foreign('landing_page_id')
                    ->references('id')
                    ->on('landing_pages')
                    ->cascadeOnDelete();
            }
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique = false): bool
    {
        $expectedColumns = array_values($columns);

        return collect(Schema::getIndexes($table))
            ->contains(function (array $index) use ($expectedColumns, $unique): bool {
                $indexColumns = array_values($index['columns'] ?? []);

                if ($indexColumns !== $expectedColumns) {
                    return false;
                }

                return ! $unique || (bool) ($index['unique'] ?? false);
            });
    }

    private function hasPrimaryIndex(string $table): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => (bool) ($index['primary'] ?? false));
    }

    private function hasForeignKey(string $table, string $column, string $foreignTable): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(function (array $foreignKey) use ($column, $foreignTable): bool {
                return in_array($column, $foreignKey['columns'] ?? [], true)
                    && ($foreignKey['foreign_table'] ?? null) === $foreignTable;
            });
    }

    private function tableHasRows(string $table): bool
    {
        return Schema::getConnection()->table($table)->exists();
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function alterTableOrFail(string $message, Closure $callback): void
    {
        try {
            Schema::table('landing_page_daily_statistics', $callback);
        } catch (Throwable $exception) {
            throw new RuntimeException($message, 0, $exception);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_daily_statistics');
    }
};
