<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Services\SizeFormatServiceTest;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';

$app->make(
    Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

echo PHP_EOL;
echo "=== Size/Format Resource Test ===" . PHP_EOL;
echo PHP_EOL;

$service = app(SizeFormatServiceTest::class);

$resources = Resource::whereNotNull('doi')->get();

echo "Found resources: " . $resources->count() . PHP_EOL;

foreach ($resources as $resource) {
    echo PHP_EOL . "Testing DOI: " . $resource->doi . PHP_EOL;

    $result = $service->extractAndProbe(
        'https://doi.org/' . $resource->doi
    );

    print_r($result);
}