<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('keeps current workers and database exports without legacy import configuration', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));
    $command = $compose['services']['queue']['command'];
    preg_match('/(?:^|\s)--queue=([^\s]+)/', $command, $matches);
    $queues = explode(',', $matches[1]);

    expect($queues)->toContain('database-dumps', 'vocabularies', 'default')
        ->not->toContain('imports');

    foreach (['app', 'queue'] as $service) {
        $environment = $compose['services'][$service]['environment'];
        expect($environment)->toContain('QUEUE_CONNECTION=database');
        foreach ($environment as $value) {
            expect($value)->not->toMatch('/^(GFZ_IGSN_PORTAL_|GFZ_DATA_SERVICES_PORTAL_|SOLR_|IGSN_LEGACY_DB_ENABLED=)/');
        }
    }
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);
