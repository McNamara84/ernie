<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\Igsn\IgsnMaterial;

class IgsnVocabularyNormalizer
{
    public function __construct(
        private readonly IgsnClassificationVocabulary $classifications = new IgsnClassificationVocabulary,
    ) {}

    public function normalizeMaterial(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || strcasecmp(trim($value), 'N/A') === 0) {
            return null;
        }

        $material = IgsnMaterial::fromImportValue($value);
        if ($material === null) {
            throw new \InvalidArgumentException('Unsupported IGSN material: '.trim($value));
        }

        return $material->value;
    }

    public function classificationType(?string $material): ?IgsnClassificationType
    {
        return IgsnMaterial::fromImportValue($material)?->classificationType();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public function normalizeClassifications(?string $material, array $values): array
    {
        $normalized = $this->partitionClassifications($material, $values);
        if ($normalized['rejected'] !== []) {
            $type = $this->classificationType($material);
            if ($type === null) {
                throw new \LogicException('Rejected IGSN classification without a controlled material type.');
            }

            throw new \InvalidArgumentException(
                sprintf('Unsupported IGSN %s classification: %s', $type->value, $normalized['rejected'][0]),
            );
        }

        return $normalized['values'];
    }

    /**
     * Legacy DIF documents can contain classifications predating the controlled
     * vocabularies. Keep valid values while reporting unknown raw values to the
     * caller so the rest of the enrichment remains usable.
     *
     * @param  list<string>  $values
     * @return array{values: list<string>, rejected: list<string>}
     */
    public function partitionClassifications(?string $material, array $values): array
    {
        $type = $this->classificationType($material);
        $result = [];
        $rejected = [];
        $seen = [];

        foreach ($values as $value) {
            $trimmed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0) {
                continue;
            }

            try {
                $canonical = $type === null
                    ? $trimmed
                    : $this->classifications->normalize($type, $trimmed);
            } catch (\InvalidArgumentException) {
                $rejected[] = $trimmed;

                continue;
            }

            if ($canonical === null) {
                continue;
            }

            $key = mb_strtolower($canonical);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $canonical;
            }
        }

        return ['values' => $result, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeImportData(array $data): array
    {
        $material = $this->normalizeMaterial(is_string($data['material'] ?? null) ? $data['material'] : null);
        $classifications = is_array($data['classification'] ?? null)
            ? array_values(array_filter($data['classification'], 'is_string'))
            : [];

        $data['material'] = $material;
        $data['classification'] = $this->normalizeClassifications($material, $classifications);

        return $data;
    }
}
