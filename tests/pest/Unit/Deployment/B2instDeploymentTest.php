<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards every B2INST option to application and worker processes with documented defaults', function (string $file): void {
    $compose = Yaml::parseFile(base_path($file));
    $example = file_get_contents(base_path('.env.docker.example'));
    $configuration = file_get_contents(base_path('config/b2inst.php'));
    preg_match_all("/env\('(B2INST_[A-Z_]+)', (?:'([^']*)'|(\d+))\)/", $configuration, $options, PREG_SET_ORDER);
    expect($options)->not->toBeEmpty();

    foreach ($options as $option) {
        $variable = $option[1];
        $default = $option[3] ?? $option[2];
        expect($example)->toMatch('/^'.preg_quote($variable.'='.$default, '/').'\r?$/m');
        foreach (['app', 'queue', 'scheduler', 'elmo-cache-refresh', 'curation-queue', 'assistance-queue', 'assessment-queue'] as $service) {
            $entries = array_values(array_filter($compose['services'][$service]['environment'], fn (string $entry): bool => str_starts_with($entry, $variable.'=')));
            expect($entries)->toBe([$variable.'=${'.$variable.':-'.$default.'}']);
        }
    }
})->with(['docker-compose.dev.yml', 'docker-compose.stage.yml', 'docker-compose.prod.yml']);
