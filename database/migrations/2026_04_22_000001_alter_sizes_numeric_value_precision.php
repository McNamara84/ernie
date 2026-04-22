<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
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
        // row holds a value that would overflow decimal(12, 4).
        $maxFor12_4 = 99_999_999.9999;
        $overflowExists = DB::table('sizes')
            ->where('numeric_value', '>', $maxFor12_4)
            ->exists();

        if ($overflowExists) {
            throw new RuntimeException(
                'Cannot revert sizes.numeric_value to decimal(12, 4): '
                .'existing rows contain values that would overflow the narrower precision.'
            );
        }

        Schema::table('sizes', function (Blueprint $table): void {
            $table->decimal('numeric_value', 12, 4)->nullable()->change();
        });
    }
};
