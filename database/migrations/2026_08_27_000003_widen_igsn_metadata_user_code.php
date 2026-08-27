<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen igsn_metadata.user_code from VARCHAR(50) to VARCHAR(255).
 *
 * Medusa DIF records use user_code for free-form project information. The
 * value used by the records reported in issue #1192 is 77 characters long,
 * so MySQL rejected the DIF enrichment with SQLSTATE[22001] and left only the
 * previously imported DataCite metadata on the landing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->string('user_code', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Refuse to narrow the column when that would silently truncate an
        // imported project description. Resolve the character-count function
        // per supported database driver so trailing spaces remain significant.
        $driver = DB::connection()->getDriverName();
        $lengthExpression = match ($driver) {
            'sqlite' => 'LENGTH(user_code)',
            'mysql', 'mariadb', 'pgsql' => 'CHAR_LENGTH(user_code)',
            'sqlsrv' => "LEN(user_code + 'x') - 1",
            default => throw new RuntimeException(
                "Unsupported database driver for igsn_metadata.user_code rollback: [{$driver}]."
            ),
        };

        $overflowExists = DB::table('igsn_metadata')
            ->where(function (Builder $query) use ($lengthExpression): void {
                $query->whereNotNull('user_code')
                    ->whereRaw("{$lengthExpression} > 50");
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert igsn_metadata.user_code to VARCHAR(50): existing rows '
                .'contain values longer than 50 characters.'
            );
        }

        Schema::table('igsn_metadata', function (Blueprint $table): void {
            $table->string('user_code', 50)->nullable()->change();
        });
    }
};
