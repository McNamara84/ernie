<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manifest = [
            'resources/js/app.tsx' => [
                'file' => 'resources/js/app.tsx',
                'src' => 'resources/js/app.tsx',
                'isEntry' => true,
            ],
        ];

        foreach (File::allFiles(resource_path('js/pages')) as $file) {
            if ($file->getExtension() !== 'tsx') {
                continue;
            }

            if (str_contains($file->getPathname(), '__tests__')) {
                continue;
            }

            $path = 'resources/js/pages/'.$file->getRelativePathname();

            $manifest[$path] = [
                'file' => $path,
                'src' => $path,
                'isEntry' => true,
            ];
        }

        File::ensureDirectoryExists(public_path('build'));

        File::put(
            public_path('build/manifest.json'),
            json_encode($manifest)
        );
    }
}
