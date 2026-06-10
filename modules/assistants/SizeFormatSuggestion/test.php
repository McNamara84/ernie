<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';

$app->make(
    Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

use App\Models\Resource;

echo PHP_EOL;
echo "=== Resource Test Data With Landing Pages ===" . PHP_EOL;
echo PHP_EOL;

$resources = Resource::with('landingPage')
    ->whereNotNull('doi')
    ->limit(50)
    ->get();

foreach ($resources as $resource) {

    echo 'ID: ' . $resource->id . PHP_EOL;
    echo 'DOI: ' . $resource->doi . PHP_EOL;

    if ($resource->landingPage) {
        echo 'Slug: ' . $resource->landingPage->slug . PHP_EOL;
        echo 'FTP URL: ' . ($resource->landingPage->ftp_url ?? 'NULL') . PHP_EOL;
    }

    echo str_repeat('-', 60) . PHP_EOL;
}