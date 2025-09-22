<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use function Pest\Laravel\withVite;

it('renders asset tags that point to the /ernie/assets base path', function () {
    config()->set('app.url', 'https://env.rz-vm182.gfz.de/ernie');
    withVite();

    $manifestPath = public_path('assets/manifest.json');
    $manifestDirectory = dirname($manifestPath);

    if (! File::exists($manifestDirectory)) {
        File::makeDirectory($manifestDirectory, 0755, true);
    }

    $originalManifest = File::exists($manifestPath)
        ? File::get($manifestPath)
        : null;

    $manifest = [
        'resources/js/app.tsx' => [
            'src' => 'resources/js/app.tsx',
            'file' => 'app-12345.js',
            'isEntry' => true,
            'css' => ['app-12345.css'],
        ],
        'resources/js/pages/welcome.tsx' => [
            'src' => 'resources/js/pages/welcome.tsx',
            'file' => 'welcome-67890.js',
            'isEntry' => true,
        ],
    ];

    File::put($manifestPath, json_encode($manifest));

    try {
        $html = Blade::render("@vite(['resources/js/app.tsx', 'resources/js/pages/welcome.tsx'])");

        expect($html)->toContain('/ernie/assets/app-12345.js');
        expect($html)->toContain('/ernie/assets/app-12345.css');
        expect($html)->toContain('/ernie/assets/welcome-67890.js');
    } finally {
        if ($originalManifest !== null) {
            File::put($manifestPath, $originalManifest);
        } else {
            File::delete($manifestPath);
        }
    }
});
