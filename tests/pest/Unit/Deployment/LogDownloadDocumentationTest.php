<?php

declare(strict_types=1);

it('documents the administrator log download workflow', function (): void {
    $documentation = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($documentation)
        ->toBeString()
        ->toContain("id: 'application-logs'")
        ->toContain("minRole: 'admin'")
        ->toContain('entire host VM')
        ->toContain('Stale')
        ->toContain('Last 24 hours')
        ->toContain('Last 7 days')
        ->toContain('Last 30 days')
        ->toContain("current table's level")
        ->toContain('search filters do not limit the file');
});

it('enables 31-day daily log retention in the production environment template', function (): void {
    $productionEnvironment = file_get_contents(base_path('.env.production'));

    expect($productionEnvironment)
        ->toBeString()
        ->toContain("LOG_CHANNEL=stack\nLOG_STACK=daily")
        ->toContain('LOG_DAILY_DAYS=31');
});

it('documents the system metric samples table and its unique timestamp in both database diagrams', function (): void {
    $mermaid = file_get_contents(database_path('er-diagram.md'));
    $plantUml = file_get_contents(database_path('er-diagram-plantuml.md'));

    expect($mermaid)
        ->toBeString()
        ->toContain('system_metric_samples {')
        ->toContain('timestamp recorded_at UK')
        ->and($plantUml)
        ->toBeString()
        ->toContain('entity "system_metric_samples" as system_metric_samples')
        ->toContain('* recorded_at : TIMESTAMP <<UK>>');
});
