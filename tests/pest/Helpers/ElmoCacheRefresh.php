<?php

declare(strict_types=1);

function configureElmoCacheRefreshTests(): void
{
    config([
        'elmo.enabled' => true,
        'elmo.targets.elmo-msl.ready' => true,
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

/**
 * Complete response from the 16-vocabulary contract of ELMO PR #1270.
 *
 * @return array{success: true, results: array<string, bool>}
 */
function elmoCacheRefreshSuccessResponse(): array
{
    return ['success' => true, 'results' => [
        'resourceTypes' => true, 'titleTypes' => true, 'descriptionTypes' => true, 'languages' => true,
        'contributorPersonRoles' => true, 'contributorInstitutionRoles' => true,
        'relationTypes' => true, 'identifierTypes' => true, 'pid4instInstruments' => true,
        'mslLabs' => true, 'gcmdScienceKeywords' => true, 'gcmdPlatforms' => true,
        'gcmdInstruments' => true, 'chronostratTimescale' => true, 'gemet' => true,
        'thesauriAvailability' => true,
    ]];
}
