<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

final class SizeFormatSizeParserService
{
    /**
     * @return array{numeric_value: string|null, unit: string|null, type: null}
     */
    public function parse(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'numeric_value' => null,
                'unit' => null,
                'type' => null,
            ];
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) === 1) {
            return [
                'numeric_value' => $value,
                'unit' => null,
                'type' => null,
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(?:\s+|(?=[^\d.\s]))(.+)$/', $value, $matches) === 1 && $this->looksLikeSizeUnit($matches[2])) {
            return [
                'numeric_value' => $matches[1],
                'unit' => $this->normalizeUnit(trim($matches[2])),
                'type' => null,
            ];
        }

        return [
            'numeric_value' => null,
            'unit' => $value,
            'type' => null,
        ];
    }

    private function looksLikeSizeUnit(string $candidate): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '' || mb_strlen($candidate) > 50) {
            return false;
        }

        if (preg_match('/[.,;:]/', $candidate) === 1) {
            return false;
        }

        return str_word_count($candidate) <= 3;
    }

    private function normalizeUnit(string $unit): string
    {
        $candidate = strtoupper(trim($unit));

        return match ($candidate) {
            'KB', 'K' => 'K',
            'MB', 'M' => 'M',
            'GB', 'G' => 'G',
            'TB', 'T' => 'T',
            'PB', 'P' => 'P',
            'B' => 'B',
            default => trim($unit),
        };
    }

}
