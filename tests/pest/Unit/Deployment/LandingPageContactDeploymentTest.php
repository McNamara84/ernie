<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards the data publication team address to the app and queue containers', function (string $composeFile): void {
    $contents = file_get_contents(base_path($composeFile));
    if ($contents === false) {
        throw new RuntimeException("Failed to read deployment file: {$composeFile}");
    }

    $compose = Yaml::parse($contents);
    if (! is_array($compose)) {
        throw new RuntimeException("Compose file did not parse to an array: {$composeFile}");
    }

    foreach (['app', 'queue'] as $service) {
        $environment = $compose['services'][$service]['environment'] ?? [];

        expect($environment)
            ->toBeArray()
            ->toContain('LANDING_PAGE_CONTACT_CC_EMAIL=${LANDING_PAGE_CONTACT_CC_EMAIL:-}');
    }
})->with([
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);
