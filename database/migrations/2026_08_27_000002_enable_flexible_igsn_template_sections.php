<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $orders = LandingPageTemplate::normalizeIgsnSectionOrders(
                    $this->decodeOrder($row->left_column_order),
                    $this->decodeOrder($row->right_column_order),
                );

                $this->updateOrders((int) $row->id, $orders['left'], $orders['right']);
            });
    }

    public function down(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $left = array_values(array_filter(
                    $this->decodeOrder($row->left_column_order),
                    static fn (string $key): bool => $key !== 'sample_image',
                ));
                $right = array_values(array_filter(
                    $this->decodeOrder($row->right_column_order),
                    static fn (string $key): bool => $key !== 'sample_image',
                ));

                $this->updateOrders((int) $row->id, $left, $right);
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

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function updateOrders(int $id, array $left, array $right): void
    {
        DB::table('landing_page_templates')->where('id', $id)->update([
            'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
            'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
