<?php

declare(strict_types=1);

namespace Tests\Helpers;

use RuntimeException;
use ZipArchive;

/**
 * @param  array<string, string|null>  $entries
 */
function sizeFormatZipFixtureData(array $entries): string
{
    if (! class_exists(ZipArchive::class)) {
        \test()->markTestSkipped('The ext-zip PHP extension is required to generate ZIP test fixtures.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'size-format-zip-test-');

    if ($temporaryPath === false) {
        throw new RuntimeException('Could not create temporary ZIP test file.');
    }

    $zip = new ZipArchive;
    $openResult = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($openResult !== true) {
        @unlink($temporaryPath);

        throw new RuntimeException('Could not open temporary ZIP test file. ZipArchive::open returned '.var_export($openResult, true).'.');
    }

    $zipClosed = false;
    $zipData = false;

    try {
        foreach ($entries as $filename => $contents) {
            $entryName = (string) $filename;
            $added = $contents === null
                ? $zip->addEmptyDir($entryName)
                : $zip->addFromString($entryName, (string) $contents);

            if ($added === false) {
                throw new RuntimeException('Could not add ZIP test entry: '.$entryName);
            }
        }

        $zipClosed = $zip->close();

        if ($zipClosed === false) {
            throw new RuntimeException('Could not finish ZIP test data.');
        }

        $zipData = file_get_contents($temporaryPath);
    } finally {
        if (! $zipClosed) {
            @$zip->close();
        }

        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }

    if ($zipData === false) {
        throw new RuntimeException('Could not read generated ZIP test data.');
    }

    return $zipData;
}
