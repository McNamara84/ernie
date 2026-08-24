<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DataCiteUrlUpdateTargetService
{
    public function targetBaseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{valid: bool, message: string|null} */
    public function validateTargetBase(): array
    {
        $target = $this->targetBaseUrl();
        $parts = parse_url($target);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            return ['valid' => false, 'message' => 'APP_URL must be a valid absolute HTTPS URL before DataCite URLs can be updated.'];
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return ['valid' => false, 'message' => 'APP_URL must not contain a query or fragment.'];
        }

        return ['valid' => true, 'message' => null];
    }

    public function buildUrl(LandingPage $landingPage): string
    {
        return $this->targetBaseUrl().$landingPage->getPublicPath();
    }

    public function isReachable(string $url): bool
    {
        try {
            $response = $this->reachabilityRequest()->head($url);

            if (! $response->successful() && $response->status() === 405) {
                $response = $this->reachabilityRequest()
                    ->withHeaders(['Range' => 'bytes=0-0'])
                    ->get($url);
            }

            if (! $response->successful()) {
                return false;
            }

            $effectiveUrl = (string) ($response->effectiveUri() ?? $url);

            return $this->hasTargetHost($effectiveUrl);
        } catch (ConnectionException) {
            return false;
        }
    }

    public function hasTargetHost(string $url): bool
    {
        $parts = parse_url($url);
        $targetParts = parse_url($this->targetBaseUrl());

        return is_array($parts)
            && is_array($targetParts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($targetParts['host'] ?? ''));
    }

    public function urlsEqual(string $left, string $right): bool
    {
        return $this->normalizeUrl($left) === $this->normalizeUrl($right);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && ! (($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80))
            ? ':'.$parts['port']
            : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    private function reachabilityRequest(): PendingRequest
    {
        return Http::connectTimeout(max(1, (int) config(
            'datacite.landing_page_url_update.reachability_connect_timeout_seconds',
            3,
        )))
            ->timeout(max(1, (int) config(
                'datacite.landing_page_url_update.reachability_timeout_seconds',
                8,
            )))
            ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true]]);
    }
}
