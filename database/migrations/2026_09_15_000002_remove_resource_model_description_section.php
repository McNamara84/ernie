<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', 'resource')
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $storedLeft = $this->decodeOrder($row->left_column_order);
                $storedRight = $this->decodeOrder($row->right_column_order);
                $left = $this->withoutModelDescription($storedLeft);
                $right = $this->withoutModelDescription($storedRight);

                if ($left === $storedLeft && $right === $storedRight) {
                    return;
                }

                DB::table('landing_page_templates')
                    ->where('id', $row->id)
                    ->update([
                        'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
                        'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', 'resource')
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $left = $this->withoutModelDescription($this->decodeOrder($row->left_column_order));
                $right = $this->withoutModelDescription($this->decodeOrder($row->right_column_order));

                if (($licensesIndex = array_search('licenses', $left, true)) !== false) {
                    array_splice($left, $licensesIndex + 1, 0, ['model_description']);
                } elseif (($licensesIndex = array_search('licenses', $right, true)) !== false) {
                    array_splice($right, $licensesIndex + 1, 0, ['model_description']);
                } else {
                    $filesIndex = array_search('files', $left, true);
                    array_splice($left, $filesIndex === false ? count($left) : $filesIndex + 1, 0, ['model_description']);
                }

                DB::table('landing_page_templates')
                    ->where('id', $row->id)
                    ->update([
                        'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
                        'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }

    /** @return list<string> */
    private function decodeOrder(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }

    /**
     * @param  list<string>  $order
     * @return list<string>
     */
    private function withoutModelDescription(array $order): array
    {
        return array_values(array_filter(
            $order,
            static fn (string $section): bool => $section !== 'model_description',
        ));
    }
};
