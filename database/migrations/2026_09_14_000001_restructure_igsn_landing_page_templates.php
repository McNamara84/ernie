<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const LEGACY_LEFT = [
        'general', 'sample_family', 'acquisition', 'igsn_methods', 'igsn_drilling',
        'repositories', 'licenses', 'citation', 'dates', 'contact',
        'model_description', 'related_work',
    ];

    /** @var list<string> */
    private const LEGACY_RIGHT = [
        'abstract', 'methods', 'technical_info', 'series_information',
        'table_of_contents', 'other', 'creators', 'contributors', 'funders',
        'keywords', 'metadata_download', 'sample_image', 'location',
    ];

    /** @var list<string> */
    private const NEW_LEFT = [
        'general', 'sample_family', 'repositories', 'map', 'related_work',
        'metadata_download', 'dates', 'citation',
    ];

    /** @var list<string> */
    private const NEW_RIGHT = [
        'version_notice', 'contributors', 'creators', 'location', 'acquisition', 'funders',
    ];

    /** @var list<string> */
    private const NEW_HIDDEN = [
        'igsn_methods', 'igsn_drilling', 'licenses', 'contact', 'model_description',
        'abstract', 'methods', 'technical_info', 'series_information',
        'table_of_contents', 'other', 'keywords', 'sample_image',
    ];

    /** @var list<string> */
    private const NEW_SECTIONS = [...self::NEW_LEFT, ...self::NEW_RIGHT, ...self::NEW_HIDDEN];

    public function up(): void
    {
        if (Schema::hasColumn('landing_page_templates', 'hidden_sections')
            && ! Schema::hasColumn('landing_page_templates', 'show_igsn_drilling')) {
            return;
        }

        if (! Schema::hasColumn('landing_page_templates', 'hidden_sections')) {
            Schema::table('landing_page_templates', function (Blueprint $table): void {
                $table->json('hidden_sections')->nullable()->after('left_column_order');
            });
        }

        DB::table('landing_page_templates')
            ->select(['id', 'is_default', 'template_type', 'left_column_order', 'right_column_order', 'show_igsn_drilling'])
            ->orderBy('id')
            ->each(function (object $row): void {
                if ($row->template_type !== LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
                    $this->updateLayout((int) $row->id, $this->decodeOrder($row->left_column_order), $this->decodeOrder($row->right_column_order), []);

                    return;
                }

                if ((bool) $row->is_default) {
                    $this->updateLayout(
                        (int) $row->id,
                        self::NEW_LEFT,
                        self::NEW_RIGHT,
                        self::NEW_HIDDEN,
                    );

                    return;
                }

                [$left, $right] = $this->uniqueKnownLayout(
                    $this->decodeOrder($row->left_column_order),
                    $this->decodeOrder($row->right_column_order),
                );

                if (! (bool) $row->show_igsn_drilling) {
                    $left = $this->without($left, 'igsn_drilling');
                    $right = $this->without($right, 'igsn_drilling');
                }

                if (($locationIndex = array_search('location', $left, true)) !== false) {
                    array_splice($left, $locationIndex + 1, 0, ['map']);
                } elseif (($locationIndex = array_search('location', $right, true)) !== false) {
                    array_splice($right, $locationIndex + 1, 0, ['map']);
                }

                array_unshift($right, 'version_notice');
                $seen = array_fill_keys([...$left, ...$right], true);
                $hidden = [];

                foreach (self::NEW_SECTIONS as $section) {
                    if (! isset($seen[$section])) {
                        $hidden[] = $section;
                    }
                }

                $this->updateLayout((int) $row->id, $left, $right, $hidden);
            });

        if (Schema::hasColumn('landing_page_templates', 'show_igsn_drilling')) {
            Schema::table('landing_page_templates', function (Blueprint $table): void {
                $table->dropColumn('show_igsn_drilling');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('landing_page_templates', 'show_igsn_drilling')) {
            Schema::table('landing_page_templates', function (Blueprint $table): void {
                $table->boolean('show_igsn_drilling')->default(true)->after('citation_author_display_limit');
            });
        }

        if (! Schema::hasColumn('landing_page_templates', 'hidden_sections')) {
            return;
        }

        DB::table('landing_page_templates')
            ->where('template_type', LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->select(['id', 'left_column_order', 'right_column_order', 'hidden_sections'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $left = $this->decodeOrder($row->left_column_order);
                $right = $this->decodeOrder($row->right_column_order);
                $hidden = $this->decodeOrder($row->hidden_sections);
                $showDrilling = ! in_array('igsn_drilling', $hidden, true);
                $visibleAndHidden = [...$left, ...$right, ...$hidden];

                $legacyLeft = $this->orderedSubset($visibleAndHidden, self::LEGACY_LEFT);
                $legacyRight = $this->orderedSubset($visibleAndHidden, self::LEGACY_RIGHT);

                DB::table('landing_page_templates')->where('id', $row->id)->update([
                    'left_column_order' => json_encode($legacyLeft, JSON_THROW_ON_ERROR),
                    'right_column_order' => json_encode($legacyRight, JSON_THROW_ON_ERROR),
                    'show_igsn_drilling' => $showDrilling,
                    'updated_at' => now(),
                ]);
            });

        Schema::table('landing_page_templates', function (Blueprint $table): void {
            $table->dropColumn('hidden_sections');
        });
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return array{list<string>, list<string>}
     */
    private function uniqueKnownLayout(array $left, array $right): array
    {
        $known = array_fill_keys([...self::LEGACY_LEFT, ...self::LEGACY_RIGHT], true);
        $seen = [];
        $filter = static function (array $sections) use ($known, &$seen): array {
            $result = [];
            foreach ($sections as $section) {
                if (isset($known[$section]) && ! isset($seen[$section])) {
                    $seen[$section] = true;
                    $result[] = $section;
                }
            }

            return $result;
        };

        return [$filter($left), $filter($right)];
    }

    /** @param list<string> $values
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function orderedSubset(array $values, array $allowed): array
    {
        $allowedSet = array_fill_keys($allowed, true);
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            if (isset($allowedSet[$value]) && ! isset($seen[$value])) {
                $seen[$value] = true;
                $result[] = $value;
            }
        }
        foreach ($allowed as $value) {
            if (! isset($seen[$value])) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function without(array $values, string $excluded): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== $excluded));
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
     * @param  list<string>  $hidden
     */
    private function updateLayout(int $id, array $left, array $right, array $hidden): void
    {
        DB::table('landing_page_templates')->where('id', $id)->update([
            'left_column_order' => json_encode($left, JSON_THROW_ON_ERROR),
            'right_column_order' => json_encode($right, JSON_THROW_ON_ERROR),
            'hidden_sections' => json_encode($hidden, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
