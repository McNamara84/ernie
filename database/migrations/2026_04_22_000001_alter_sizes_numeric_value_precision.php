<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen sizes.numeric_value from decimal(12, 4) to decimal(20, 4).
 *
 * decimal(12, 4) caps at ~99,999,999.9999 which overflows on legitimate
 * byte-sized values coming from the DataCite API (e.g. "2675059373 Bytes").
 * decimal(20, 4) allows up to 16 digits before the decimal point and easily
 * covers byte counts in the petabyte range while remaining compatible with
 * the existing `decimal:4` Eloquent cast on App\Models\Size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            $table->decimal('numeric_value', 20, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Guard against data loss: refuse to narrow the column if any existing
        // row holds a value outside the signed decimal(12, 4) range
        // [-99999999.9999, 99999999.9999].
        //
        // Use string bounds rather than PHP float literals: decimal(12, 4) can
        // represent values that exceed PHP float precision (IEEE-754 double,
        // ~15–17 significant digits), so a float-based comparison could
        // misclassify boundary values after implicit float-to-string conversion.
        // Query bindings are sent to the database as strings, which MySQL
        // coerces using exact DECIMAL arithmetic.
        $upperBound = '99999999.9999';
        $lowerBound = '-99999999.9999';

        $overflowExists = DB::table('sizes')
            ->where(function (Builder $query) use ($upperBound, $lowerBound): void {
                $query->where('numeric_value', '>', $upperBound)
                    ->orWhere('numeric_value', '<', $lowerBound);
            })
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert sizes.numeric_value to decimal(12, 4): '
                .'existing rows contain values outside the narrower precision '
                ."range [{$lowerBound}, {$upperBound}]."
            );
        }

        Schema::table('sizes', function (Blueprint $table): void {
            $table->decimal('numeric_value', 12, 4)->nullable()->change();
        });
    }
};
