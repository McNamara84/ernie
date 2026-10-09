<?php

declare(strict_types=1);

namespace App\Services;

final class ApplicationLogSourceService
{
    /** @return list<array{path: string, size: int, order: int}> */
    public function sources(): array
    {
        $directory = realpath(storage_path('logs'));
        if ($directory === false) {
            return [];
        }
        $sources = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== 'laravel.log' && (preg_match('/^laravel-(\d{4})-(\d{2})-(\d{2})\.log$/', $name, $match) !== 1
                || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1]))) {
                continue;
            }
            $candidate = $directory.DIRECTORY_SEPARATOR.$name;
            clearstatcache(true, $candidate);
            $path = realpath($candidate);
            if (is_link($candidate) || $path === false || dirname($path) !== $directory || ! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size !== false) {
                $sources[] = ['path' => $path, 'size' => $size, 'order' => count($sources)];
            }
        }

        return $sources;
    }
}
