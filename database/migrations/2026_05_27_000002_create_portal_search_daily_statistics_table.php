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
        if (Schema::hasTable('portal_search_daily_statistics')) {
            $this->repairExistingTable();

            return;
        }

        $this->createTable();
    }

    private function createTable(): void
    {
        Schema::create('portal_search_daily_statistics', function (Blueprint $table): void {
            $table->id();
            $table->date('statistic_date');
            $table->string('normalized_term', 255);
            $table->unsignedInteger('search_count')->default(0);
            $table->timestamps();

            $table->unique(['statistic_date', 'normalized_term'], 'portal_search_stats_date_term_unique');
            $table->index('normalized_term');
            $table->index('statistic_date');
        });
    }

    private function repairExistingTable(): void
    {
        $this->ensurePrimaryIdColumn();
        $this->ensureColumns();
        $this->ensureIndexes();
    }

    private function ensurePrimaryIdColumn(): void
    {
        if (Schema::hasColumn('portal_search_daily_statistics', 'id')) {
            return;
        }

        if ($this->hasPrimaryIndex('portal_search_daily_statistics')) {
            throw new RuntimeException(
                'Cannot repair portal_search_daily_statistics: table has a primary key but no id column.'
            );
        }

        if ($this->isSqlite()) {
            throw new RuntimeException(
                'Cannot repair portal_search_daily_statistics on SQLite: adding an auto-incrementing id column to an existing table is unsupported.'
            );
        }

        $this->alterTableOrFail(
            'Cannot add portal_search_daily_statistics.id. Inspect the existing table schema before rerunning migrations.',
            function (Blueprint $table): void {
                $table->id()->first();
            }
        );
    }

    private function ensureColumns(): void
    {
        $missingRequiredColumns = array_filter([
            'statistic_date' => ! Schema::hasColumn('portal_search_daily_statistics', 'statistic_date'),
            'normalized_term' => ! Schema::hasColumn('portal_search_daily_statistics', 'normalized_term'),
        ]);

        if ($missingRequiredColumns !== [] && $this->tableHasRows('portal_search_daily_statistics')) {
            $columns = implode(', ', array_keys($missingRequiredColumns));

            throw new RuntimeException(
                "Cannot repair portal_search_daily_statistics: existing rows are missing required column(s): {$columns}."
            );
        }

        if ($missingRequiredColumns !== [] && $this->isSqlite()) {
            $columns = implode(', ', array_keys($missingRequiredColumns));

            throw new RuntimeException(
                "Cannot repair portal_search_daily_statistics on SQLite: adding required non-null column(s) is unsupported: {$columns}."
            );
        }

        $missingColumns = [
            'statistic_date' => ! Schema::hasColumn('portal_search_daily_statistics', 'statistic_date'),
            'normalized_term' => ! Schema::hasColumn('portal_search_daily_statistics', 'normalized_term'),
            'search_count' => ! Schema::hasColumn('portal_search_daily_statistics', 'search_count'),
            'created_at' => ! Schema::hasColumn('portal_search_daily_statistics', 'created_at'),
            'updated_at' => ! Schema::hasColumn('portal_search_daily_statistics', 'updated_at'),
        ];

        if (! in_array(true, $missingColumns, true)) {
            return;
        }

        $this->alterTableOrFail(
            'Cannot repair portal_search_daily_statistics columns. Inspect the existing table schema before rerunning migrations.',
            function (Blueprint $table) use ($missingColumns): void {
                if ($missingColumns['statistic_date']) {
                    $table->date('statistic_date')->after('id');
                }

                if ($missingColumns['normalized_term']) {
                    $table->string('normalized_term', 255)->after('statistic_date');
                }

                if ($missingColumns['search_count']) {
                    $table->unsignedInteger('search_count')->default(0)->after('normalized_term');
                }

                if ($missingColumns['created_at']) {
                    $table->timestamp('created_at')->nullable()->after('search_count');
                }

                if ($missingColumns['updated_at']) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            }
        );
    }

    private function ensureIndexes(): void
    {
        if (! $this->hasIndex('portal_search_daily_statistics', ['statistic_date', 'normalized_term'], unique: true)) {
            $this->alterTableOrFail(
                'Cannot add portal_search_daily_statistics unique index. Existing rows may contain duplicate statistic_date/normalized_term pairs.',
                function (Blueprint $table): void {
                    $table->unique(['statistic_date', 'normalized_term'], 'portal_search_stats_date_term_unique');
                }
            );
        }

        if (! $this->hasIndex('portal_search_daily_statistics', ['normalized_term'])) {
            $this->alterTableOrFail(
                'Cannot add portal_search_daily_statistics.normalized_term index.',
                function (Blueprint $table): void {
                    $table->index('normalized_term');
                }
            );
        }

        if (! $this->hasIndex('portal_search_daily_statistics', ['statistic_date'])) {
            $this->alterTableOrFail(
                'Cannot add portal_search_daily_statistics.statistic_date index.',
                function (Blueprint $table): void {
                    $table->index('statistic_date');
                }
            );
        }
    }

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

    private function tableHasRows(string $table): bool
    {
        return Schema::getConnection()->table($table)->exists();
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function alterTableOrFail(string $message, callable $callback): void
    {
        try {
            Schema::table('portal_search_daily_statistics', $callback);
        } catch (Throwable $exception) {
            throw new RuntimeException($message, 0, $exception);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_search_daily_statistics');
    }
};
