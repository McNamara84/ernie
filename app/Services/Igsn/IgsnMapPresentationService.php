<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnMaterial;
use Illuminate\Support\Str;

/**
 * Converts controlled IGSN material values into a bounded map category.
 *
 * Exact material values remain available for marker details, while clusters
 * use only the top-level material category. Unexpected legacy values are
 * deliberately folded into one category so map payload cardinality stays
 * bounded.
 */
final class IgsnMapPresentationService
{
    public const DIMENSION = 'material';

    public const MISSING_KEY = 'missing';

    public const NOT_APPLICABLE_KEY = 'not-applicable';

    public const UNRECOGNIZED_KEY = 'unrecognized';

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     status: 'value'|'not-applicable'|'missing'|'unrecognized',
     *     material: string|null,
     *     materialLabel: string|null
     * }
     */
    public function forMaterial(?string $value): array
    {
        $trimmed = $value === null ? '' : trim($value);

        if ($trimmed === '' || mb_strtolower($trimmed) === 'n/a') {
            return [
                'key' => self::MISSING_KEY,
                'label' => 'No material provided',
                'status' => 'missing',
                'material' => null,
                'materialLabel' => null,
            ];
        }

        $material = IgsnMaterial::fromImportValue($trimmed);
        if (! $material instanceof IgsnMaterial) {
            return [
                'key' => self::UNRECOGNIZED_KEY,
                'label' => 'Unrecognized material',
                'status' => 'unrecognized',
                'material' => $trimmed,
                'materialLabel' => $this->pathLabel($trimmed),
            ];
        }

        if ($material === IgsnMaterial::NOT_APPLICABLE) {
            return [
                'key' => self::NOT_APPLICABLE_KEY,
                'label' => $material->label(),
                'status' => 'not-applicable',
                'material' => $material->value,
                'materialLabel' => $material->label(),
            ];
        }

        $root = explode('>', $material->value, 2)[0];
        $rootMaterial = IgsnMaterial::tryFrom($root);

        return [
            'key' => Str::kebab($root),
            'label' => $rootMaterial?->label() ?? $root,
            'status' => 'value',
            'material' => $material->value,
            'materialLabel' => $this->pathLabel($material->value),
        ];
    }

    private function pathLabel(string $value): string
    {
        return implode(' › ', array_map('trim', explode('>', $value)));
    }
}
