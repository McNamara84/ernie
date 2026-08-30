<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function productionDomainCompose(): array
{
    $contents = file_get_contents(base_path('docker-compose.prod.yml'));
    if ($contents === false) {
        throw new RuntimeException('Failed to read docker-compose.prod.yml.');
    }

    $compose = Yaml::parse($contents);
    if (! is_array($compose)) {
        throw new RuntimeException('Production Compose file did not parse to an array.');
    }

    return $compose;
}

/** @param list<mixed> $values */
function productionEnvironmentValue(array $values, string $name): ?string
{
    foreach ($values as $value) {
        if (is_string($value) && str_starts_with($value, "{$name}=")) {
            return substr($value, strlen($name) + 1);
        }
    }

    return null;
}

/**
 * @param  list<mixed>  $values
 * @return array<string, string>
 */
function productionTraefikLabels(array $values): array
{
    $labels = [];

    foreach ($values as $value) {
        if (! is_string($value) || ! str_contains($value, '=')) {
            continue;
        }

        [$name, $labelValue] = explode('=', $value, 2);
        $labels[$name] = $labelValue;
    }

    return $labels;
}

function productionComposeLiteral(string $value): string
{
    return str_replace('$$', '$', $value);
}

it('uses one canonical production URL without changing persistent OAI identifiers', function (): void {
    $compose = productionDomainCompose();

    foreach (['app', 'queue'] as $service) {
        $environment = $compose['services'][$service]['environment'] ?? null;

        expect($environment)->toBeArray()
            ->and(productionEnvironmentValue($environment, 'APP_URL'))
            ->toBe('${APP_URL:-https://dataservices.gfz.de}')
            ->and(productionEnvironmentValue($environment, 'OAI_IDENTIFIER_PREFIX'))
            ->toBe('${OAI_IDENTIFIER_PREFIX:-oai:ernie.rz-vm499.gfz.de}')
            ->and(productionEnvironmentValue($environment, 'DATACITE_USER_AGENT_EMAIL'))
            ->toBe('${DATACITE_USER_AGENT_EMAIL:-datapub@gfz.de}');
    }

    $appEnvironment = $compose['services']['app']['environment'];
    expect(productionEnvironmentValue($appEnvironment, 'SESSION_DOMAIN'))->toBe('dataservices.gfz.de')
        ->and(productionEnvironmentValue($appEnvironment, 'SANCTUM_STATEFUL_DOMAINS'))->toBe('dataservices.gfz.de');
});

it('routes only confirmed whole legacy path segments away from ERNIE', function (): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);

    expect($labels['traefik.http.routers.ernie-router.rule'] ?? null)
        ->toBe('Host(`dataservices.gfz.de`)');

    $legacyRule = $labels['traefik.http.routers.dataservices-legacy-router.rule'] ?? '';
    expect($legacyRule)->toContain('Host(`dataservices.gfz.de`)')
        ->and($legacyRule)->toContain('PathRegexp(`')
        ->and($legacyRule)->toContain('(/|$$)');

    preg_match('/PathRegexp\(`(.+)`\)/', $legacyRule, $matches);
    $legacyPattern = productionComposeLiteral($matches[1] ?? '');
    expect($legacyPattern)->not->toBe('')
        ->and($legacyPattern)->toEndWith('(/|$)');

    $legacySegments = [
        '4dmb', 'arbodat', 'b2find', 'bfo', 'caos', 'contact', 'dekorp',
        'dekorp-tryout', 'digis', 'dome', 'enmap', 'extern', 'generalinclude',
        'geoxlabs', 'gipp', 'grace', 'gracefo', 'gravis', 'icdp', 'icgem',
        'igets', 'igsn', 'igsn-new', 'igsnstats', 'igsntest', 'intermagnet',
        'isg', 'lib', 'mesi', 'msl', 'msl-old', 'msl-tryout', 'muell',
        'panmetaworks', 'panmetaworks-tryout', 'pik', 'portal', 'reassign',
        'restricted', 'riesgos', 'SDDB', 'tereno', 'tereno-new', 'thesaurus',
        'web', 'wsm',
    ];

    foreach ($legacySegments as $segment) {
        expect(preg_match('~'.$legacyPattern.'~', "/{$segment}"))->toBe(1)
            ->and(preg_match('~'.$legacyPattern.'~', "/{$segment}/example"))->toBe(1);
    }

    foreach (['/', '/search', '/search/map', '/igsns', '/igsns-map', '/thesauri', '/images/gfz-logo_en.svg', '/10.5880/example/slug'] as $erniePath) {
        expect(preg_match('~'.$legacyPattern.'~', $erniePath))->toBe(0);
    }

    expect($labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.replacement'] ?? null)
        ->toBe('https://dataservices.gfz-potsdam.de/$${1}');

    $legacyRedirectRegex = $labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.regex'] ?? '';
    $legacyRedirectReplacement = productionComposeLiteral(
        $labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.replacement'] ?? '',
    );
    $legacyUrl = 'https://dataservices.gfz.de/panmetaworks/showshort.php?id=example';

    expect(preg_replace('~'.$legacyRedirectRegex.'~', $legacyRedirectReplacement, $legacyUrl))
        ->toBe('https://dataservices.gfz-potsdam.de/panmetaworks/showshort.php?id=example');
});

it('maps former ERNIE portal bookmarks to search before applying the canonical redirect', function (): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);

    expect($labels['traefik.http.routers.ernie-old-portal-router.rule'] ?? null)
        ->toBe('Host(`ernie.rz-vm499.gfz.de`) && PathRegexp(`^/portal(/|$$)`)')
        ->and($labels['traefik.http.routers.ernie-old-portal-router.priority'] ?? null)
        ->toBe('300')
        ->and($labels['traefik.http.middlewares.ernie-old-portal-redirect.redirectregex.replacement'] ?? null)
        ->toBe('https://dataservices.gfz.de/search$${1}')
        ->and($labels['traefik.http.routers.ernie-old-router.rule'] ?? null)
        ->toBe('Host(`ernie.rz-vm499.gfz.de`)')
        ->and($labels['traefik.http.routers.ernie-old-router.priority'] ?? null)
        ->toBe('200')
        ->and($labels['traefik.http.middlewares.ernie-old-redirect.redirectregex.replacement'] ?? null)
        ->toBe('https://dataservices.gfz.de/$${1}');

    $portalRedirectRegex = $labels['traefik.http.middlewares.ernie-old-portal-redirect.redirectregex.regex'] ?? '';
    $portalRedirectReplacement = productionComposeLiteral(
        $labels['traefik.http.middlewares.ernie-old-portal-redirect.redirectregex.replacement'] ?? '',
    );
    expect(preg_replace('~'.$portalRedirectRegex.'~', $portalRedirectReplacement, 'https://ernie.rz-vm499.gfz.de/portal?q=test'))
        ->toBe('https://dataservices.gfz.de/search?q=test');

    $canonicalRedirectRegex = $labels['traefik.http.middlewares.ernie-old-redirect.redirectregex.regex'] ?? '';
    $canonicalRedirectReplacement = productionComposeLiteral(
        $labels['traefik.http.middlewares.ernie-old-redirect.redirectregex.replacement'] ?? '',
    );
    expect(preg_replace('~'.$canonicalRedirectRegex.'~', $canonicalRedirectReplacement, 'https://ernie.rz-vm499.gfz.de/10.5880/example/slug'))
        ->toBe('https://dataservices.gfz.de/10.5880/example/slug');
});
