<?php

declare(strict_types=1);

use App\Exceptions\DataCiteVerificationException;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Services\ResourceDoiPublicationTargetService;
use App\Services\SizeFormat\SizeFormatDownloadTargetResolverService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

covers(ResourceDoiPublicationTargetService::class);

beforeEach(function (): void {
    config(['app.url' => 'https://ernie.example']);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    Http::preventStrayRequests();
    $this->resource = (new Resource)->forceFill(['id' => 42]);
    $this->page = new LandingPage([
        'resource_id' => 42, 'template' => 'external', 'external_domain_id' => 1,
        'external_path' => 'approved', 'is_published' => true, 'slug' => 'approved',
    ]);
    $this->page->setRelation('externalDomain', new LandingPageDomain(['domain' => 'https://external.example/']));
    $this->resource->setRelation('landingPage', $this->page);
});

it('rejects non-public DNS destinations before sending any HTTP request', function (array $addresses): void {
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(fn (): array => $addresses));
    Http::fake();

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class, 'not publicly reachable');
    Http::assertNothingSent();
})->with([
    'loopback' => [['127.0.0.1']],
    'private class A' => [['10.0.0.1']],
    'private class B' => [['172.16.0.1']],
    'private class C' => [['192.168.1.1']],
    'link local / metadata service' => [['169.254.169.254']],
    'shared address space' => [['100.64.0.1']],
    'IPv4 multicast' => [['224.0.0.1']],
    'IPv6 loopback' => [['::1']],
    'IPv6 private' => [['fd00::1']],
    'IPv6 link local' => [['fe80::1']],
    'IPv6 multicast' => [['ff02::1']],
    'IPv4-mapped loopback' => [['::ffff:127.0.0.1']],
    'mixed public and private' => [['93.184.216.34', '10.0.0.1']],
    'mixed IPv4 and private IPv6' => [['93.184.216.34', 'fd00::1']],
    'unresolved' => [[]],
]);

it('rejects private literal hosts and non-HTTPS or nonstandard external ports', function (string $domain): void {
    $this->page->externalDomain->domain = $domain;
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(fn (): array => ['93.184.216.34']));
    Http::fake();

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertNothingSent();
})->with([
    'https://127.0.0.1/', 'https://10.0.0.1/', 'https://[::1]/', 'https://[::ffff:127.0.0.1]/',
    'https://localhost/', 'https://external.example:8443/', 'https://external.example:80/',
    'http://external.example/', 'https://user:password@external.example/',
]);

it('pins public DNS answers for HEAD, fallback GET and redirects while preserving the HTTPS hostname', function (string $address, string $pin): void {
    $lookups = 0;
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(function (string $host) use (&$lookups, $address): array {
        expect($host)->toBe('external.example');
        $lookups++;

        return [$address];
    }));
    Http::fake(function (Request $request, array $options) use ($pin) {
        expect($request->hasHeader('Authorization'))->toBeFalse()
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['proxy'])->toBe('')
            ->and($options['verify'])->toBeTrue()
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe([$pin])
            ->and($options['curl'][CURLOPT_PROXY])->toBe('')
            ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
            ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue();
        if ($request->method() === 'GET') {
            expect($request->header('Range'))->toBe(['bytes=0-0']);

            return Http::response('', 302, ['Location' => '/canonical']);
        }

        return Http::response('', str_ends_with($request->url(), '/approved') ? 405 : 200);
    });

    $service->assertReachable($this->resource, $service->target($this->resource));

    expect($lookups)->toBe(3);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://external.example/canonical');
})->with([
    'IPv4' => ['93.184.216.34', 'external.example:443:93.184.216.34'],
    'IPv6' => ['2001:4860:4860::8888', 'external.example:443:[2001:4860:4860::8888]'],
]);

it('blocks DNS rebinding before a redirect or fallback GET connects to the new private address', function (int $status): void {
    $lookups = 0;
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(
        function () use (&$lookups): array {
            return ++$lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        },
    ));
    Http::fake(fn () => Http::response('', $status, ['Location' => '/canonical']));

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    expect($lookups)->toBe(2);
    Http::assertSentCount(1);
})->with(['redirect' => 302, 'GET fallback' => 405]);

it('rejects external redirects to a different origin or unsafe URL before following them', function (string $location): void {
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(fn (): array => ['93.184.216.34']));
    Http::fake(fn () => Http::response('', 302, ['Location' => $location]));

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertSentCount(1);
})->with([
    'https://127.0.0.1/secret', 'https://other.example/page', 'https://external.example:8443/page',
    'http://external.example/page', '//127.0.0.1/page', 'https://user:secret@external.example/page',
    '/login', '/page?preview_token=secret', '/page#fragment',
]);

it('limits external redirect chains to three hops', function (): void {
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(fn (): array => ['93.184.216.34']));
    Http::fake(fn () => Http::response('', 302, ['Location' => '/loop']));

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertSentCount(4);
});

it('allows the trusted private application origin and its canonical redirect without public DNS validation', function (): void {
    config(['app.url' => 'https://127.0.0.1:3333']);
    URL::forceRootUrl('https://127.0.0.1:3333');
    $this->page->template = 'default_gfz';
    $canonical = $this->page->public_url;
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(function (): array {
        throw new RuntimeException('The trusted application must not use the external resolver.');
    }));
    Http::fake(fn (Request $request) => $request->url() === $canonical
        ? Http::response('', 200) : Http::response('', 302, ['Location' => $canonical]));

    $service->assertReachable($this->resource, $service->target($this->resource));

    Http::assertSentCount(2);
});

it('does not give external pages the private application origin exemption', function (): void {
    config(['app.url' => 'https://127.0.0.1']);
    $this->page->externalDomain->domain = 'https://127.0.0.1/';
    Http::fake();
    $service = app(ResourceDoiPublicationTargetService::class);

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertNothingSent();
});

it('accepts public literal IP destinations without attempting DNS resolution', function (string $domain): void {
    $this->page->externalDomain->domain = $domain;
    $service = new ResourceDoiPublicationTargetService(new SizeFormatDownloadTargetResolverService(function (): array {
        throw new RuntimeException('Literal addresses do not need DNS.');
    }));
    Http::fake(function (Request $request, array $options) {
        expect($options['curl'][CURLOPT_RESOLVE])->toBe([]);

        return Http::response('', 200);
    });

    $service->assertReachable($this->resource, $service->target($this->resource));

    Http::assertSentCount(1);
})->with(['https://93.184.216.34/', 'https://[2001:4860:4860::8888]/']);

it('rejects an internal target outside the configured application origin', function (): void {
    $this->page->template = 'default_gfz';
    URL::forceRootUrl('https://127.0.0.1:8443');
    $service = app(ResourceDoiPublicationTargetService::class);
    Http::fake();

    expect(fn () => $service->assertReachable($this->resource, $service->target($this->resource)))
        ->toThrow(DataCiteVerificationException::class);
    Http::assertNothingSent();
});
