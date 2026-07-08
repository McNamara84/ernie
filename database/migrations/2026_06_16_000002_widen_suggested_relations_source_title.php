<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suggested_relations') || ! Schema::hasColumn('suggested_relations', 'source_title')) {
            return;
        }

        Schema::table('suggested_relations', function (Blueprint $table): void {
            $table->text('source_title')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suggested_relations') || ! Schema::hasColumn('suggested_relations', 'source_title')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(source_title)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(source_title)',
            'sqlsrv' => "LEN(source_title + 'x') - 1",
            default => throw new RuntimeException(
                "Unsupported database driver for suggested_relations.source_title rollback: [{$driver}]."
            ),
        };

        $overflowExists = DB::table('suggested_relations')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('source_title')
                    ->whereRaw("{$lengthExpression} > 255");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert suggested_relations.source_title to VARCHAR(255): existing rows '
                .'contain values longer than 255 characters.'
            );
        }

        Schema::table('suggested_relations', function (Blueprint $table): void {
            $table->string('source_title')->nullable()->change();
        });
    }
};
