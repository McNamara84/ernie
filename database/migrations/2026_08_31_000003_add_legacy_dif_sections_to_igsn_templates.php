<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const SECTIONS = ['igsn_methods', 'igsn_drilling'];

    public function up(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $template): void {
                $left = $this->withoutNewSections($this->decode($template->left_column_order));
                $right = $this->withoutNewSections($this->decode($template->right_column_order));
                $index = array_search('acquisition', $left, true);
                array_splice($left, $index === false ? count($left) : $index + 1, 0, self::SECTIONS);

                DB::table('landing_page_templates')->where('id', $template->id)->update([
                    'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
                    'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $template): void {
                DB::table('landing_page_templates')->where('id', $template->id)->update([
                    'left_column_order' => json_encode($this->withoutNewSections($this->decode($template->left_column_order)), JSON_THROW_ON_ERROR),
                    'right_column_order' => json_encode($this->withoutNewSections($this->decode($template->right_column_order)), JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }

    /** @return list<string> */
    private function decode(mixed $value): array
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

    /** @param list<string> $sections
     * @return list<string>
     */
    private function withoutNewSections(array $sections): array
    {
        return array_values(array_filter(
            $sections,
            static fn (string $section): bool => ! in_array($section, self::SECTIONS, true),
        ));
    }
};
