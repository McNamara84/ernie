<?php

declare(strict_types=1);

it('documents the administrator log download workflow', function (): void {
    $documentation = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($documentation)
        ->toBeString()
        ->toContain("id: 'application-logs'")
        ->toContain("minRole: 'admin'")
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
