<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Models\Resource;
use App\Services\SizeFormat\SizeFormatDownloadTargetResolverService;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

class ResourceDoiPublicationTargetService
{
    public function __construct(private readonly SizeFormatDownloadTargetResolverService $resolver) {}

    public function target(Resource $resource): string
    {
        $page = $resource->landingPage;
        if ($page === null || $page->is_tombstone) {
            throw new DataCiteVerificationException('landing_page_required', 'A configured, non-tombstone landing page is required for publication.');
        }

        return $page->isExternal()
            ? ($page->external_url ?? throw new DataCiteVerificationException('invalid_target', 'The external landing-page URL is missing.'))
            : url('/datasets/'.$resource->id);
    }

    public function assertReachable(Resource $resource, string $target): void
    {
        $canonical = $resource->landingPage?->public_url;
        $external = $resource->landingPage?->isExternal() === true;
        if ($target !== $this->target($resource)
            || (! $external && ! $this->sameOrigin($target, (string) config('app.url')))) {
            throw $this->unreachable();
        }
        // Do not follow arbitrary redirects or send credentials to the target.
        // External pages must resolve on their explicitly configured host.
        $url = $target;
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $parts = parse_url($url);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
                || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || ! $this->sameOrigin($url, $target)
                || preg_match('~/(?:preview|login|sign-?in)(?:/|$)|[?&](?:preview|token|preview_token)=~i', $url)) {
                break;
            }
            $response = $this->request($url, $external)->head($url);
            if ($response->status() === 405) {
                $response = $this->request($url, $external)->withHeaders(['Range' => 'bytes=0-0'])->get($url);
            }
            if ($response->successful()) {
                return;
            }
            $next = $response->header('Location');
            if (! $response->redirect() || $next === '') {
                break;
            }
            if (str_starts_with($next, '/') && ! str_starts_with($next, '//')) {
                $next = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$next;
            }
            if (! $this->sameOrigin($next, $target)
                || (! $resource->landingPage?->isExternal() && $next !== $canonical)) {
                break;
            }
            $url = $next;
        }

        throw $this->unreachable();
    }

    private function request(string $url, bool $external): PendingRequest
    {
        $request = Http::connectTimeout(3)->timeout(8)->withoutRedirecting();
        if (! $external) {
            // Only the configured application origin and its canonical page are
            // trusted, including private addresses/ports used by local stacks.
            return $request;
        }

        if ((parse_url($url, PHP_URL_PORT) ?? 443) !== 443 || ! extension_loaded('curl')) {
            throw $this->unreachable();
        }
        $destination = $this->resolver->resolve($url);
        if ($destination === null
            || filter_var($destination['address'], FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false
            || IpUtils::checkIp($destination['address'], ['224.0.0.0/4', 'ff00::/8'])) {
            throw $this->unreachable();
        }

        // Resolve again for every HEAD, GET and redirect, then pin the checked
        // address without changing TLS SNI or the Host header. Proxies and
        // pooled connections must not bypass this destination check.
        // Literal IP URLs already fix the destination and need no DNS entry.
        return $request->setHandler(new CurlHandler)->withOptions([
            'proxy' => '',
            'verify' => true,
            'curl' => [
                CURLOPT_RESOLVE => filter_var($destination['host'], FILTER_VALIDATE_IP) !== false
                    ? [] : [$destination['curl_resolve']],
                CURLOPT_PROXY => '',
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ],
        ]);
    }

    private function sameOrigin(string $url, string $expected): bool
    {
        $parts = parse_url($url);
        $target = parse_url($expected);

        return is_array($parts) && is_array($target)
            && ($parts['scheme'] ?? '') === 'https' && ($target['scheme'] ?? '') === 'https'
            && isset($parts['host'], $target['host'])
            && strtolower($parts['host']) === strtolower($target['host'])
            && ($parts['port'] ?? 443) === ($target['port'] ?? 443);
    }

    private function unreachable(): DataCiteVerificationException
    {
        return new DataCiteVerificationException('doi_target_unreachable', 'The approved landing page is not publicly reachable. DOI publication has not been sent.', true);
    }
}
