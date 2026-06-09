<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap Laravel
|--------------------------------------------------------------------------
*/

require __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';

$app->make(
    Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

use App\Models\Resource;
use Illuminate\Support\Facades\Http;

echo PHP_EOL;
echo "=== DOI Lookup Test ===" . PHP_EOL;
echo PHP_EOL;

$resources = Resource::query()
    ->whereNotNull('doi')
    ->limit(20)
    ->get();

foreach ($resources as $resource) {

    $doi = trim($resource->doi);

    echo "Resource ID : {$resource->id}" . PHP_EOL;
    echo "DOI         : {$doi}" . PHP_EOL;

    try {

        $response = Http::withoutRedirecting()
            ->timeout(10)
            ->head("https://doi.org/{$doi}");

        echo "Status      : " . $response->status() . PHP_EOL;
        echo "URL         : "
            . ($response->header('Location') ?? 'NOT FOUND')
            . PHP_EOL;

    } catch (\Throwable $e) {

        echo "ERROR       : "
            . $e->getMessage()
            . PHP_EOL;
    }

    echo str_repeat('-', 50) . PHP_EOL;
}