<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The legacy section ownership immediately before the three-zone layout.
     *
     * Keep these lists local: model-level layout constants may change in later
     * releases, but this migration's rollback must remain deterministic.
     *
     * @var list<string>
     */
    private const LEGACY_LEFT_COLUMN_SECTIONS = [
        'general',
        'sample_family',
        'acquisition',
        'igsn_methods',
        'igsn_drilling',
        'repositories',
        'licenses',
        'citation',
        'dates',
        'contact',
        'model_description',
        'related_work',
    ];

    /** @var list<string> */
    private const LEGACY_RIGHT_COLUMN_SECTIONS = [
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
        'location',
    ];

    /** @var list<string> */
    private const FLEXIBLE_RIGHT_COLUMN_SECTIONS = [
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
        'sample_image',
        'location',
    ];

    /** @var list<string> */
    private const FLEXIBLE_SECTIONS = [
        ...self::LEGACY_LEFT_COLUMN_SECTIONS,
        ...self::FLEXIBLE_RIGHT_COLUMN_SECTIONS,
    ];

    public function up(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $orders = $this->normalizeOrders(
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
                $allSections = array_values(array_unique([
                    ...$this->decodeOrder($row->left_column_order),
                    ...$this->decodeOrder($row->right_column_order),
                ]));
                $left = array_values(array_filter(
                    $allSections,
                    static fn (string $key): bool => in_array($key, self::LEGACY_LEFT_COLUMN_SECTIONS, true),
                ));
                $right = array_values(array_filter(
                    $allSections,
                    static fn (string $key): bool => in_array($key, self::LEGACY_RIGHT_COLUMN_SECTIONS, true),
                ));

                $this->updateOrders((int) $row->id, $left, $right);
            });
    }

    /**
     * Preserve the two-column normalization behavior from this migration's
     * release instead of depending on the application's current layout model.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return array{left: list<string>, right: list<string>}
     */
    private function normalizeOrders(array $left, array $right): array
    {
        $valid = array_fill_keys(self::FLEXIBLE_SECTIONS, true);
        $seen = [];
        $normalizedLeft = [];
        $normalizedRight = [];

        foreach ($left as $key) {
            if (isset($valid[$key]) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $normalizedLeft[] = $key;
            }
        }
        foreach ($right as $key) {
            if (isset($valid[$key]) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $normalizedRight[] = $key;
            }
        }

        $hasStoredCitation = isset($seen['citation']);
        foreach (self::LEGACY_LEFT_COLUMN_SECTIONS as $key) {
            if ($key === 'citation' && ! $hasStoredCitation) {
                continue;
            }
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $normalizedLeft[] = $key;
            }
        }
        if (! $hasStoredCitation) {
            $seen['citation'] = true;
            $normalizedLeft[] = 'citation';
        }

        foreach (self::FLEXIBLE_RIGHT_COLUMN_SECTIONS as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $locationIndex = $key === 'sample_image'
                ? array_search('location', $normalizedRight, true)
                : false;
            if ($locationIndex === false) {
                $normalizedRight[] = $key;
            } else {
                array_splice($normalizedRight, $locationIndex, 0, [$key]);
            }
        }

        return ['left' => $normalizedLeft, 'right' => $normalizedRight];
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
