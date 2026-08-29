<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'igsn_classifications_resource_value_unique';

    public function up(): void
    {
        $duplicates = DB::table('igsn_classifications')
            ->select(['resource_id', 'value'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('resource_id', 'value')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('igsn_classifications')
                ->where('resource_id', (int) $duplicate->resource_id)
                ->where('value', (string) $duplicate->value)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'classification_type']);
            $keeper = $rows->first();
            if ($keeper === null) {
                continue;
            }

            if (! is_string($keeper->classification_type)) {
                $classificationType = $rows
                    ->pluck('classification_type')
                    ->first(static fn (mixed $value): bool => is_string($value));

                if (is_string($classificationType)) {
                    DB::table('igsn_classifications')
                        ->where('id', (int) $keeper->id)
                        ->whereNull('classification_type')
                        ->update([
                            'classification_type' => $classificationType,
                            'updated_at' => now(),
                        ]);
                }
            }

            $duplicateIds = $rows
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->reject(static fn (int $id): bool => $id === (int) $keeper->id)
                ->values()
                ->all();

            if ($duplicateIds !== []) {
                DB::table('igsn_classifications')->whereIn('id', $duplicateIds)->delete();
            }
        }

        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->unique(['resource_id', 'value'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('igsn_classifications', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
