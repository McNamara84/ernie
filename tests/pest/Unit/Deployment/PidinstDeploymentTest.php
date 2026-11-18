<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards DataCite discovery options and the shared budget to every writer', function (string $file): void {
    $compose = Yaml::parseFile(base_path($file));
    $defaults = ['HOST' => 'https://api.datacite.org', 'PAGE_SIZE' => '1000', 'CONNECT_TIMEOUT' => '10',
        'TIMEOUT' => '30', 'MAX_PAGES' => '1000', 'MAX_RECORDS' => '100000'];
    foreach (['.env.example', '.env.docker.example'] as $example) {
        $content = file_get_contents(base_path($example));
        foreach ($defaults as $option => $value) {
            expect($content)->toMatch('/^'.preg_quote('PIDINST_DATACITE_'.$option.'='.$value, '/').'\r?$/m');
        }
    }
    foreach (['app', 'queue', 'scheduler', 'elmo-cache-refresh', 'curation-queue', 'assistance-queue', 'assessment-queue'] as $service) {
        $environment = $compose['services'][$service]['environment'];
        foreach ($defaults as $option => $value) {
            $variable = 'PIDINST_DATACITE_'.$option;
            expect($environment)->toContain($variable.'=${'.$variable.':-'.$value.'}');
        }
        expect($environment)->toContain('PIDINST_MAX_DURATION=${PIDINST_MAX_DURATION:-${B2INST_MAX_DURATION:-900}}');
    }
})->with(['docker-compose.dev.yml', 'docker-compose.stage.yml', 'docker-compose.prod.yml']);
