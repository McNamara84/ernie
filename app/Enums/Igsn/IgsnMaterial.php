<?php

declare(strict_types=1);

namespace App\Enums\Igsn;

enum IgsnMaterial: string
{
    case BIOLOGY = 'Biology';
    case GAS = 'Gas';
    case ICE = 'Ice';
    case LIQUID_AQUEOUS = 'Liquid>aqueous';
    case LIQUID_AQUEOUS_POREWATER = 'Liquid>aqueous>porewater';
    case LIQUID_ORGANIC = 'Liquid>organic';
    case MINERAL = 'Mineral';
    case NOT_APPLICABLE = 'NotApplicable';
    case ORGANIC_MATERIAL = 'Organic Material';
    case OTHER = 'Other';
    case PARTICULATE = 'Particulate';
    case ROCK = 'Rock';
    case SEDIMENT = 'Sediment';
    case SNOW = 'Snow';
    case SOIL = 'Soil';
    case SYNTHETIC = 'Synthetic';
    case TEPHRA = 'Tephra';

    public function label(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'Not applicable',
            default => $this->value,
        };
    }

    public function classificationType(): ?IgsnClassificationType
    {
        return match ($this) {
            self::ROCK => IgsnClassificationType::ROCK,
            self::MINERAL => IgsnClassificationType::MINERAL,
            self::BIOLOGY => IgsnClassificationType::BIOLOGY,
            default => null,
        };
    }

    public static function fromImportValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::normalize($value);
        if ($normalized === '' || $normalized === 'n/a') {
            return null;
        }

        if ($normalized === 'not applicable') {
            return self::NOT_APPLICABLE;
        }

        foreach (self::cases() as $material) {
            if (self::normalize($material->value) === $normalized) {
                return $material;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }
}
