<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\Resource;
use App\Models\Size;

final class DigitalContentSizeService
{
    /** @var array<string, string> */
    private const FACTORS = [
        'B' => '1',
        'byte' => '1',
        'bytes' => '1',
        'kB' => '1000',
        'KB' => '1000',
        'MB' => '1000000',
        'GB' => '1000000000',
        'TB' => '1000000000000',
        'PB' => '1000000000000000',
        'KiB' => '1024',
        'MiB' => '1048576',
        'GiB' => '1073741824',
        'TiB' => '1099511627776',
        'PiB' => '1125899906842624',
    ];

    public function forResource(Size $size, Resource $resource): ?string
    {
        if ($resource->isIgsn() || $size->resource_id !== $resource->id) {
            return null;
        }

        return $this->toBytes($size);
    }

    public function toBytes(Size $size): ?string
    {
        try {
            $value = trim((string) $size->numeric_value);
        } catch (\Throwable) {
            return null;
        }

        $unit = trim((string) $size->unit);
        $factor = self::FACTORS[$unit] ?? null;

        if ($value === '' || $factor === null || preg_match('/\A\d+(?:\.\d+)?\z/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $digits = ltrim($whole.$fraction, '0');

        if ($digits === '') {
            return null;
        }

        $product = $this->multiply($digits, $factor);
        $scale = strlen($fraction);

        if ($scale === 0) {
            return $product;
        }

        if (strlen($product) <= $scale) {
            $product = str_pad($product, $scale + 1, '0', STR_PAD_LEFT);
        }

        $integer = substr($product, 0, -$scale);
        $remainder = substr($product, -$scale);

        if (trim($remainder, '0') !== '') {
            return null;
        }

        return ltrim($integer, '0') ?: '0';
    }

    public function isEligible(Size $size, Resource $resource): bool
    {
        return $this->forResource($size, $resource) !== null;
    }

    private function multiply(string $left, string $right): string
    {
        $result = array_fill(0, strlen($left) + strlen($right), 0);

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; $rightIndex--) {
                $result[$leftIndex + $rightIndex + 1] += ((int) $left[$leftIndex]) * ((int) $right[$rightIndex]);
            }
        }

        for ($index = count($result) - 1; $index > 0; $index--) {
            $result[$index - 1] += intdiv($result[$index], 10);
            $result[$index] %= 10;
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }
}
