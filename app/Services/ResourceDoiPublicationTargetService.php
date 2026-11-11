<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Models\Resource;
use Illuminate\Support\Facades\Http;

class ResourceDoiPublicationTargetService
{
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
        // Do not follow arbitrary redirects or send credentials to the target.
        // External pages must resolve on their explicitly configured host.
        $url = $target;
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $parts = parse_url($url);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || preg_match('~/(?:preview|login|sign-?in)(?:/|$)|[?&](?:preview|token|preview_token)=~i', $url)) {
                break;
            }
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->head($url);
            if ($response->status() === 405) {
                $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withHeaders(['Range' => 'bytes=0-0'])->get($url);
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
            if (parse_url($next, PHP_URL_HOST) !== parse_url($target, PHP_URL_HOST)
                || (! $resource->landingPage?->isExternal() && $next !== $canonical)) {
                break;
            }
            $url = $next;
        }

        throw new DataCiteVerificationException('doi_target_unreachable', 'The approved landing page is not publicly reachable. DOI publication has not been sent.', true);
    }
}
