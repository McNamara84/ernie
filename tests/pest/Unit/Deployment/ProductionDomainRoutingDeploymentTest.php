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

dataset('production search redirects', [
    'DOI portal' => ['ernie-doi-search-redirect-router', 'ernie-doi-search-redirect', '/portal', '/doi-search'],
    'IGSN portal' => ['ernie-igsn-search-redirect-router', 'ernie-igsn-search-redirect', '/igsn-new', '/igsn-search'],
]);

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

it('leaves the ELMO paths to their separate Docker stacks', function (): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);

    $ernieRule = $labels['traefik.http.routers.ernie-router.rule'] ?? '';
    expect($ernieRule)
        ->toBe('Host(`dataservices.gfz.de`) && !PathRegexp(`^/(elmo|elmo-msl)(/|$$)`)');

    preg_match('/!PathRegexp\(`(.+)`\)/', $ernieRule, $matches);
    $externalPattern = productionComposeLiteral($matches[1] ?? '');
    expect($externalPattern)->not->toBe('')
        ->and(preg_match('~'.$externalPattern.'~', '/elmo'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo/'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo/subpath'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo-msl'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo-msl/'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo-msl/subpath'))->toBe(1)
        ->and(preg_match('~'.$externalPattern.'~', '/elmos'))->toBe(0)
        ->and(preg_match('~'.$externalPattern.'~', '/elmo-msls'))->toBe(0)
        ->and(preg_match('~'.$externalPattern.'~', '/api/v1/elmo/vocabularies'))->toBe(0);
});

it('keeps the homepage in ERNIE and redirects only confirmed whole legacy path segments', function (): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);

    $legacyRule = $labels['traefik.http.routers.dataservices-legacy-router.rule'] ?? '';
    expect($legacyRule)->toContain('Host(`dataservices.gfz.de`)')
        ->and($legacyRule)->not->toContain('Path(`/`)')
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
        'igets', 'igsn', 'igsnstats', 'igsntest', 'intermagnet',
        'isg', 'lib', 'mesi', 'msl', 'msl-old', 'msl-tryout', 'muell',
        'panmetaworks', 'panmetaworks-tryout', 'pik', 'reassign',
        'restricted', 'riesgos', 'SDDB', 'tereno', 'tereno-new', 'thesaurus',
        'web', 'wsm',
    ];

    foreach ($legacySegments as $segment) {
        expect(preg_match('~'.$legacyPattern.'~', "/{$segment}"))->toBe(1)
            ->and(preg_match('~'.$legacyPattern.'~', "/{$segment}/example"))->toBe(1);
    }

    foreach (['/', '/search', '/search/map', '/doi-search', '/doi-search/map', '/igsn-search', '/igsn-search/map', '/login', '/igsns', '/igsns-map', '/thesauri', '/images/gfz-logo_en.svg', '/images/home/topics/HEx_buttons_atmosphere.png', '/10.5880/example/slug'] as $erniePath) {
        expect(preg_match('~'.$legacyPattern.'~', $erniePath))->toBe(0);
    }

    foreach (['/portal', '/portal/', '/portal/index.php', '/portal/a/b', '/igsn-new', '/igsn-new/', '/igsn-new/portal/results', '/igsn-new/a/b'] as $searchPath) {
        expect(preg_match('~'.$legacyPattern.'~', $searchPath))->toBe(0);
    }

    expect($labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.replacement'] ?? null)
        ->toBe('https://dataservices.gfz-potsdam.de/$${1}')
        ->and($labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.permanent'] ?? null)
        ->toBe('false');

    $legacyRedirectRegex = $labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.regex'] ?? '';
    $legacyRedirectReplacement = productionComposeLiteral(
        $labels['traefik.http.middlewares.dataservices-legacy-redirect.redirectregex.replacement'] ?? '',
    );
    $legacyUrl = 'https://dataservices.gfz.de/panmetaworks/showshort.php?id=example';

    expect(preg_replace('~'.$legacyRedirectRegex.'~', $legacyRedirectReplacement, $legacyUrl))
        ->toBe('https://dataservices.gfz-potsdam.de/panmetaworks/showshort.php?id=example');

});

it('gives permanent search redirects precedence on the canonical production host', function (string $router, string $middleware, string $source, string $target): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);
    $routerPrefix = "traefik.http.routers.{$router}.";
    $middlewarePrefix = "traefik.http.middlewares.{$middleware}.redirectregex.";
    $canonicalPriority = (int) ($labels['traefik.http.routers.ernie-router.priority']
        ?? strlen(productionComposeLiteral($labels['traefik.http.routers.ernie-router.rule'] ?? '')));

    expect($labels[$routerPrefix.'rule'] ?? null)
        ->toBe('Host(`dataservices.gfz.de`) && PathRegexp(`^'.$source.'(/|$$)`)')
        ->and($labels[$routerPrefix.'entrypoints'] ?? null)->toBe('https')
        ->and($labels[$routerPrefix.'service'] ?? null)->toBe('ernie-service')
        ->and($labels[$routerPrefix.'middlewares'] ?? null)->toBe($middleware)
        ->and((int) ($labels[$routerPrefix.'priority'] ?? 0))
        ->toBeGreaterThan((int) ($labels['traefik.http.routers.dataservices-legacy-router.priority'] ?? 0))
        ->toBeGreaterThan($canonicalPriority)
        ->and($labels[$middlewarePrefix.'permanent'] ?? null)->toBe('true')
        ->and($labels[$middlewarePrefix.'replacement'] ?? null)->toBe('https://dataservices.gfz.de'.$target);
})->with('production search redirects');

it('matches complete legacy search path segments without capturing other ERNIE or legacy routes', function (string $router, string $middleware, string $source, string $target): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);
    preg_match('/PathRegexp\(`(.+)`\)/', $labels["traefik.http.routers.{$router}.rule"] ?? '', $matches);
    $pattern = productionComposeLiteral($matches[1] ?? '');
    expect($pattern)->not->toBe('');

    foreach (['', '/', '/index.php', '/portal/results', '/a/b/c', '//nested', '/a%20b', '/a%2Fb'] as $suffix) {
        expect(preg_match('~'.$pattern.'~', $source.$suffix))->toBe(1);
    }

    foreach ([$source.'s', $source.'-test', $source.'er', $source.'.php', strtoupper($source), '/prefix'.$source, '/', '/portal', '/igsn-new', '/web/', '/igsn', '/igsns', '/elmo', '/elmo-msl', '/doi-search', '/doi-search/map', '/doi-search/count', '/igsn-search', '/igsn-search/map', '/igsn-search/count', '/health', '/login', '/10.5880/example/slug'] as $path) {
        if ($path === $source) {
            continue;
        }

        expect(preg_match('~'.$pattern.'~', $path))->toBe(0);
    }
})->with('production search redirects');

it('discards every legacy search subpath and query when redirecting to the fixed search start page', function (string $router, string $middleware, string $source, string $target): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);
    $prefix = "traefik.http.middlewares.{$middleware}.redirectregex.";
    $pattern = productionComposeLiteral($labels[$prefix.'regex'] ?? '');
    $replacement = productionComposeLiteral($labels[$prefix.'replacement'] ?? '');
    $targetUrl = 'https://dataservices.gfz.de'.$target;

    expect($pattern)->not->toBe('');

    foreach (['', '/', '/index.php', '/a/b/c', '//nested', '?', '?q=granite&page=2', '/?q=granite&page=2', '/index.php?old=value', '/a/b?old=value&sort=desc', '?q=a%2Fb%3Fc%3Dd', '/a%20b?q=test', '/a%2Fb?filter[]=one&filter[]=two', '?redirect=https://example.org/portal?q=test', '?path=/igsn-new/&path=/doi-search'] as $suffix) {
        $url = 'https://dataservices.gfz.de'.$source.$suffix;

        expect(preg_match('~'.$pattern.'~', $url))->toBe(1)
            ->and(preg_replace('~'.$pattern.'~', $replacement, $url))->toBe($targetUrl);
    }

    foreach (['https://example.org'.$source, 'https://ernie.rz-vm499.gfz.de'.$source, 'https://dataservices.gfz-potsdam.de'.$source, 'https://dataservices.gfz.de.example.org'.$source, 'https://DATASERVICES.GFZ.DE.example.org:443'.$source, 'https://sub.dataservices.gfz.de'.$source, 'https://dataservices.gfz.de..'.$source, 'https://dataservices.gfz.de:abc'.$source, 'https://dataservicesXgfzXde'.$source, 'http://dataservices.gfz.de'.$source, 'https://dataservices.gfz.de'.$source.'s?q=test', 'https://dataservices.gfz.de'.$source.'-test/', 'https://dataservices.gfz.de'.$source.'.php', 'https://DATASERVICES.GFZ.DE.:443'.strtoupper($source), $targetUrl, $targetUrl.'?q=test', $targetUrl.'/map'] as $url) {
        expect(preg_match('~'.$pattern.'~', $url))->toBe(0)
            ->and(preg_replace('~'.$pattern.'~', $replacement, $url))->toBe($url);
    }
})->with('production search redirects');

it('redirects equivalent canonical host spellings to the same search start page', function (string $router, string $middleware, string $source, string $target, string $host): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);
    $prefix = "traefik.http.middlewares.{$middleware}.redirectregex.";
    $pattern = productionComposeLiteral($labels[$prefix.'regex'] ?? '');
    $replacement = productionComposeLiteral($labels[$prefix.'replacement'] ?? '');

    expect($pattern)->not->toBe('');

    foreach (['', '/', '?q=granite&page=2', '/index.php?q=test', '/a/b?q=a%2Fb%3Fc%3Dd'] as $suffix) {
        $url = 'https://'.$host.$source.$suffix;

        expect(preg_match('~'.$pattern.'~', $url))->toBe(1)
            ->and(preg_replace('~'.$pattern.'~', $replacement, $url))->toBe('https://dataservices.gfz.de'.$target);
    }
})->with('production search redirects')->with([
    'uppercase host' => 'DATASERVICES.GFZ.DE',
    'mixed-case host' => 'DataServices.GfZ.De',
    'explicit HTTPS port' => 'dataservices.gfz.de:443',
    'other numeric port' => 'dataservices.gfz.de:8443',
    'trailing dot' => 'dataservices.gfz.de.',
    'uppercase host with trailing dot' => 'DATASERVICES.GFZ.DE.',
    'mixed-case host with trailing dot and HTTPS port' => 'DataServices.GfZ.De.:443',
    'trailing dot with other numeric port' => 'dataservices.gfz.de.:8443',
]);

it('maps former ERNIE portal bookmarks to the DOI portal before applying the canonical redirect', function (): void {
    $compose = productionDomainCompose();
    $labels = productionTraefikLabels($compose['services']['webserver']['labels'] ?? []);

    expect($labels['traefik.http.routers.ernie-old-portal-router.rule'] ?? null)
        ->toBe('Host(`ernie.rz-vm499.gfz.de`) && PathRegexp(`^/portal(/|$$)`)')
        ->and($labels['traefik.http.routers.ernie-old-portal-router.priority'] ?? null)
        ->toBe('300')
        ->and($labels['traefik.http.middlewares.ernie-old-portal-redirect.redirectregex.replacement'] ?? null)
        ->toBe('https://dataservices.gfz.de/doi-search$${1}')
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
        ->toBe('https://dataservices.gfz.de/doi-search?q=test');

    $canonicalRedirectRegex = $labels['traefik.http.middlewares.ernie-old-redirect.redirectregex.regex'] ?? '';
    $canonicalRedirectReplacement = productionComposeLiteral(
        $labels['traefik.http.middlewares.ernie-old-redirect.redirectregex.replacement'] ?? '',
    );
    expect(preg_replace('~'.$canonicalRedirectRegex.'~', $canonicalRedirectReplacement, 'https://ernie.rz-vm499.gfz.de/10.5880/example/slug'))
        ->toBe('https://dataservices.gfz.de/10.5880/example/slug');
});
