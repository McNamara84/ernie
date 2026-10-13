<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards independent public page switches with the correct deployment defaults', function (string $composeFile, string $default): void {
    $compose = Yaml::parseFile(base_path($composeFile));
    $environment = $compose['services']['app']['environment'];

    foreach (['PUBLIC_HOME_ENABLED', 'PUBLIC_FIND_ENABLED', 'PUBLIC_DATA_CENTRES_ENABLED', 'PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED'] as $name) {
        expect($environment)->toContain($name.'=${'.$name.':-'.$default.'}');
    }
})->with([
    'development' => ['docker-compose.dev.yml', 'true'],
    'stage' => ['docker-compose.stage.yml', 'true'],
    'production' => ['docker-compose.prod.yml', 'false'],
]);

it('reads true and false environment values as public page booleans', function (string $value, bool $enabled): void {
    $names = ['PUBLIC_HOME_ENABLED', 'PUBLIC_FIND_ENABLED', 'PUBLIC_DATA_CENTRES_ENABLED', 'PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED'];
    $previous = [];

    foreach ($names as $name) {
        $previous[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    try {
        $config = require config_path('public_pages.php');

        expect($config)->toBe([
            'home_enabled' => $enabled,
            'find_enabled' => $enabled,
            'data_centres_enabled' => $enabled,
            'data_centre_description_enabled' => $enabled,
        ]);
    } finally {
        foreach ($previous as $name => $original) {
            if ($original === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $original;
            }
        }
    }
})->with(['enabled' => ['true', true], 'disabled' => ['false', false]]);
