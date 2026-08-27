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
        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->string('start_date', 10)->nullable()->change();
            $table->string('end_date', 10)->nullable()->change();
            $table->string('temporal_mode', 8)->nullable()->after('end_date');
        });

        // Rows written before this migration were always exported as
        // intervals, including start-only and end-only values.
        DB::table('geo_locations')
            ->whereNull('temporal_mode')
            ->where(function (Builder $query): void {
                $query->whereNotNull('start_date')->orWhereNotNull('end_date');
            })
            ->update(['temporal_mode' => 'interval']);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(%s)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(%s)',
            'sqlsrv' => 'LEN(%s)',
            default => throw new RuntimeException(
                "Unsupported database driver for temporal coverage rollback: [{$driver}]."
            ),
        };
        $unrepresentableValuesExist = DB::table('geo_locations')
            ->where('temporal_mode', 'instant')
            ->orWhere(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('start_date')->whereRaw(sprintf($lengthExpression, 'start_date').' <> 10');
            })
            ->orWhere(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('end_date')->whereRaw(sprintf($lengthExpression, 'end_date').' <> 10');
            })
            ->exists();

        if ($unrepresentableValuesExist) {
            throw new RuntimeException(
                'Cannot revert temporal coverage storage: reduced-precision dates or temporal instants would lose information.'
            );
        }

        Schema::table('geo_locations', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
            $table->dropColumn('temporal_mode');
        });
    }
};
