<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('passes the matching test repository identity and credentials to deployed app and queue services', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));
    foreach (['app', 'queue'] as $service) {
        expect($compose['services'][$service]['environment'])->toContain(
            'DATACITE_TEST_MODE=${DATACITE_TEST_MODE:-true}',
            'DATACITE_TEST_ENDPOINT=${DATACITE_TEST_ENDPOINT:-https://api.test.datacite.org}',
            'DATACITE_TEST_CLIENT_ID=${DATACITE_TEST_CLIENT_ID:-}',
            'DATACITE_TEST_USERNAME=${DATACITE_TEST_USERNAME:-}',
            'DATACITE_TEST_PASSWORD=${DATACITE_TEST_PASSWORD:-}',
        );
    }
})->with(['docker-compose.stage.yml', 'docker-compose.prod.yml']);

it('documents the required test repository ID in the production environment template', function (): void {
    expect(file_get_contents(base_path('.env.production')))->toContain("\nDATACITE_TEST_CLIENT_ID=\n");
});
