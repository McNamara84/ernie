<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\OldDataset;

echo "=== Testing getDescriptions() Method ===\n\n";

// Get first dataset
$dataset = OldDataset::first();

if ($dataset) {
    echo "Dataset ID: {$dataset->id}\n";
    echo "DOI: {$dataset->identifier}\n\n";
    
    echo "Testing getDescriptions() method...\n";
    $descriptions = $dataset->getDescriptions();
    
    echo "Found " . count($descriptions) . " descriptions:\n\n";
    
    foreach ($descriptions as $index => $desc) {
        echo "Description " . ($index + 1) . ":\n";
        echo "  Type: {$desc['type']}\n";
        echo "  Content: " . substr($desc['description'], 0, 100) . "...\n\n";
    }
    
    if (count($descriptions) === 0) {
        echo "No descriptions found for this dataset. This is OK.\n";
    }
} else {
    echo "No datasets found in database.\n";
}

echo "\n=== Test completed successfully! ===\n";
