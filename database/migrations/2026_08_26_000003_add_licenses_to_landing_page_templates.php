<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('landing_page_templates')
            ->select(['id', 'template_type', 'left_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $order = array_values(array_filter(
                    $this->decodeOrder($row->left_column_order),
                    static fn (string $key): bool => $key !== 'licenses',
                ));
                $anchor = $row->template_type === 'igsn' ? 'repositories' : 'files';
                $anchorIndex = array_search($anchor, $order, true);
                $citationIndex = array_search('citation', $order, true);
                $insertAt = $anchorIndex !== false
                    ? $anchorIndex + 1
                    : ($citationIndex !== false ? $citationIndex : count($order));

                array_splice($order, $insertAt, 0, ['licenses']);

                $this->updateOrder((int) $row->id, $order);
            });
    }

    public function down(): void
    {
        DB::table('landing_page_templates')
            ->select(['id', 'left_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $order = array_values(array_filter(
                    $this->decodeOrder($row->left_column_order),
                    static fn (string $key): bool => $key !== 'licenses',
                ));

                $this->updateOrder((int) $row->id, $order);
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

    /** @param list<string> $order */
    private function updateOrder(int $id, array $order): void
    {
        DB::table('landing_page_templates')
            ->where('id', $id)
            ->update([
                'left_column_order' => json_encode($order, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
