<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

final class SizeFormatFormatNormalizer
{
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $normalized = strtolower(ltrim($trimmed, '.'));

        if (str_contains($normalized, '/')) {
            return trim(explode(';', $normalized)[0]);
        }

        if (str_ends_with($normalized, '.gz')) {
            return 'application/gzip';
        }

        return match ($normalized) {
            '7z' => 'application/x-7z-compressed',
            'asc' => 'application/pgp-signature',
            'bin', 'dat' => 'application/octet-stream',
            'bz2' => 'application/x-bzip2',
            'csv' => 'text/csv',
            'gz', 'tgz' => 'application/gzip',
            'h5', 'hdf5' => 'application/x-hdf5',
            'hdf' => 'application/x-hdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'json' => 'application/json',
            'kmz' => 'application/vnd.google-earth.kmz',
            'md' => 'text/markdown',
            'nc', 'netcdf' => 'application/x-netcdf',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'rar' => 'application/vnd.rar',
            'tar' => 'application/x-tar',
            'tif', 'tiff' => 'image/tiff',
            'tsv' => 'text/tab-separated-values',
            'txt' => 'text/plain',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xml' => 'application/xml',
            'xz' => 'application/x-xz',
            'zip' => 'application/zip',
            default => $normalized,
        };
    }
}
