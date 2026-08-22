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
        if (! Schema::hasTable('descriptions') || ! Schema::hasColumn('descriptions', 'language')) {
            return;
        }

        Schema::table('descriptions', function (Blueprint $table): void {
            $table->string('language', 35)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('descriptions') || ! Schema::hasColumn('descriptions', 'language')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(language)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(language)',
            'sqlsrv' => "LEN(language + 'x') - 1",
            default => throw new RuntimeException(
                "Unsupported database driver for descriptions.language rollback: [{$driver}]."
            ),
        };

        $overflowExists = DB::table('descriptions')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('language')
                    ->whereRaw("{$lengthExpression} > 10");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert descriptions.language to VARCHAR(10): existing rows contain longer language tags.'
            );
        }

        Schema::table('descriptions', function (Blueprint $table): void {
            $table->string('language', 10)->nullable()->change();
        });
    }
};
