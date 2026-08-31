<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const DESCRIPTION_SECTIONS = [
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
    ];

    /** @var list<string> */
    private const LEFT_CANONICAL = [
        'files',
        'licenses',
        'citation',
        'dates',
        'contact',
        'model_description',
        'related_work',
    ];

    /** @var list<string> */
    private const RIGHT_CANONICAL = [
        ...self::DESCRIPTION_SECTIONS,
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
        'location',
    ];

    /** @var list<string> */
    private const METADATA_SECTIONS = [
        ...self::DESCRIPTION_SECTIONS,
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
    ];

    public function up(): void
    {
        DB::table('landing_page_templates')
            ->where('template_type', 'resource')
            ->select(['id', 'slug', 'is_default', 'left_column_order', 'right_column_order'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $storedLeft = $this->decodeOrder($row->left_column_order);
                $storedRight = $this->decodeOrder($row->right_column_order);

                if ((bool) $row->is_default || $row->slug === 'default_gfz') {
                    $left = self::LEFT_CANONICAL;
                    $right = self::RIGHT_CANONICAL;
                } else {
                    ['left' => $left, 'right' => $right] = $this->normalize($storedLeft, $storedRight);
                }

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
        // No-op: moving modules back to their historical columns would discard
        // intentional user configuration and is not a safe rollback.
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return array{left: list<string>, right: list<string>}
     */
    private function normalize(array $left, array $right): array
    {
        $valid = array_fill_keys([...self::LEFT_CANONICAL, ...self::RIGHT_CANONICAL], true);
        $seen = [];
        $normalizedLeft = [];
        $normalizedRight = [];

        $this->appendKnown($normalizedLeft, $left, $valid, $seen);
        $this->appendKnown($normalizedRight, $right, $valid, $seen);

        $hasStoredCitation = isset($seen['citation']);

        if (! isset($seen['licenses'])) {
            $seen['licenses'] = true;
            $filesIndex = array_search('files', $normalizedLeft, true);
            $citationIndex = array_search('citation', $normalizedLeft, true);
            $insertAt = $filesIndex !== false
                ? $filesIndex + 1
                : ($citationIndex !== false ? $citationIndex : count($normalizedLeft));
            array_splice($normalizedLeft, $insertAt, 0, ['licenses']);
        }

        foreach (self::LEFT_CANONICAL as $key) {
            if ($key === 'licenses' || ($key === 'citation' && ! $hasStoredCitation)) {
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

        foreach (self::RIGHT_CANONICAL as $key) {
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $normalizedRight[] = $key;
            }
        }

        return [
            'left' => $this->groupMetadata($normalizedLeft),
            'right' => $this->groupMetadata($normalizedRight),
        ];
    }

    /**
     * @param  list<string>  $target
     * @param  list<string>  $stored
     * @param  array<string, true>  $valid
     * @param  array<string, true>  $seen
     */
    private function appendKnown(array &$target, array $stored, array $valid, array &$seen): void
    {
        foreach ($stored as $key) {
            $keys = $key === 'descriptions' ? self::DESCRIPTION_SECTIONS : [$key];

            foreach ($keys as $expandedKey) {
                if (! isset($valid[$expandedKey]) || isset($seen[$expandedKey])) {
                    continue;
                }

                $seen[$expandedKey] = true;
                $target[] = $expandedKey;
            }
        }
    }

    /**
     * @param  list<string>  $order
     * @return list<string>
     */
    private function groupMetadata(array $order): array
    {
        $metadataSet = array_fill_keys(self::METADATA_SECTIONS, true);
        $metadata = [];
        $standalone = [];
        $insertAt = null;

        foreach ($order as $key) {
            if (isset($metadataSet[$key])) {
                $insertAt ??= count($standalone);
                $metadata[] = $key;
            } else {
                $standalone[] = $key;
            }
        }

        if ($metadata !== []) {
            array_splice($standalone, $insertAt ?? count($standalone), 0, $metadata);
        }

        return $standalone;
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
};
