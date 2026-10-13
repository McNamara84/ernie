<?php

declare(strict_types=1);

function configureElmoCacheRefreshTests(): void
{
    config([
        'elmo.enabled' => true,
        'elmo.targets.elmo.base_url' => 'https://stage.example/elmo/',
        'elmo.targets.elmo.api_key' => 'elmo-secret',
        'elmo.targets.elmo-msl.base_url' => 'https://stage.example/elmo-msl',
        'elmo.targets.elmo-msl.api_key' => 'msl-secret',
        'elmo.connect_timeout' => 10,
        'elmo.timeout' => 310,
        'elmo.job_timeout' => 330,
        'queue.connections.elmo.retry_after' => 390,
    ]);
}
