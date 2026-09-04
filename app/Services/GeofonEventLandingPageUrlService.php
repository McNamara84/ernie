<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeofonEventLandingPageUrlService
{
    public const string CANONICAL_DOMAIN = 'https://geofon.gfz.de/';

    /** @var list<string> */
    public const array KNOWN_HOSTS = [
        'geofon.gfz.de',
        'geofon.gfz-potsdam.de',
    ];

    /**
     * @return array{
     *     status: 'legacy'|'current'|'unknown'|'invalid',
     *     recognized_host: bool,
     *     event_id: string|null,
     *     target_url: string|null,
     *     needs_update: bool,
     *     message: string|null
     * }
     */
    public function inspect(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $this->inspection('invalid', message: 'The landing-page URL is empty.');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return $this->inspection('invalid', message: 'The landing-page URL cannot be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $this->inspection('invalid', message: 'The landing-page URL must be an absolute HTTP(S) URL.');
        }

        $recognizedHost = in_array($host, self::KNOWN_HOSTS, true);
        if (! $recognizedHost) {
            return $this->inspection(
                'unknown',
                message: "The landing-page host {$host} is not an allowed GEOFON host.",
            );
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return $this->inspection(
                'invalid',
                recognizedHost: true,
                message: 'GEOFON event URLs must not contain user information, a port, or a fragment.',
            );
        }

        $path = (string) ($parts['path'] ?? '');
        $status = match ($path) {
            '/db/eqpage.php' => 'legacy',
            '/eqinfo/event.php' => 'current',
            default => null,
        };

        if ($status === null) {
            return $this->inspection(
                'unknown',
                recognizedHost: true,
                message: "The GEOFON path {$path} is not a supported event-page path.",
            );
        }

        $query = (string) ($parts['query'] ?? '');
        if (preg_match('/\Aid=([A-Za-z0-9._-]+)\z/D', $query, $matches) !== 1) {
            return $this->inspection(
                'invalid',
                recognizedHost: true,
                message: 'GEOFON event URLs must contain exactly one non-empty id query parameter.',
            );
        }

        $eventId = strtolower($matches[1]);
        $targetUrl = $this->targetUrl($eventId);

        return $this->inspection(
            $status,
            recognizedHost: true,
            eventId: $eventId,
            targetUrl: $targetUrl,
            needsUpdate: ! $this->urlsEqual($trimmed, $targetUrl),
        );
    }

    public function eventIdFromDoi(string $doi): ?string
    {
        $normalized = strtolower(trim($doi));
        if (preg_match(
            '/\A(?:10\.1594\/gfz\.geofon|10\.5880\/geofon)\.(gfz[0-9]{4}[a-z]{4})\z/D',
            $normalized,
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function targetUrl(string $eventId): string
    {
        return self::CANONICAL_DOMAIN.'eqinfo/event.php?id='.rawurlencode(strtolower(trim($eventId)));
    }

    /**
     * @return array{reachable: bool, http_status: int|null, effective_url: string|null, message: string|null}
     */
    public function probe(string $targetUrl): array
    {
        try {
            $response = $this->reachabilityRequest()->head($targetUrl);
            if ($response->status() === 405) {
                $response = $this->reachabilityRequest()
                    ->withHeaders(['Range' => 'bytes=0-0'])
                    ->get($targetUrl);
            }

            $effectiveUrl = (string) ($response->effectiveUri() ?? $targetUrl);
            if (! $response->successful()) {
                return [
                    'reachable' => false,
                    'http_status' => $response->status(),
                    'effective_url' => $effectiveUrl,
                    'message' => "The target landing page returned HTTP {$response->status()}.",
                ];
            }

            if (! $this->urlsEqual($effectiveUrl, $targetUrl)) {
                return [
                    'reachable' => false,
                    'http_status' => $response->status(),
                    'effective_url' => $effectiveUrl,
                    'message' => 'The target landing page resolved to an unexpected URL.',
                ];
            }

            return [
                'reachable' => true,
                'http_status' => $response->status(),
                'effective_url' => $effectiveUrl,
                'message' => null,
            ];
        } catch (ConnectionException $exception) {
            return [
                'reachable' => false,
                'http_status' => null,
                'effective_url' => null,
                'message' => 'The target landing page could not be reached: '.$exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'reachable' => false,
                'http_status' => null,
                'effective_url' => null,
                'message' => 'The target landing page check failed: '.$exception->getMessage(),
            ];
        }
    }

    public function urlsEqual(string $left, string $right): bool
    {
        return $this->normalizeUrl($left) !== null
            && $this->normalizeUrl($left) === $this->normalizeUrl($right);
    }

    /**
     * @param  'legacy'|'current'|'unknown'|'invalid'  $status
     * @return array{
     *     status: 'legacy'|'current'|'unknown'|'invalid',
     *     recognized_host: bool,
     *     event_id: string|null,
     *     target_url: string|null,
     *     needs_update: bool,
     *     message: string|null
     * }
     */
    private function inspection(
        string $status,
        bool $recognizedHost = false,
        ?string $eventId = null,
        ?string $targetUrl = null,
        bool $needsUpdate = false,
        ?string $message = null,
    ): array {
        return [
            'status' => $status,
            'recognized_host' => $recognizedHost,
            'event_id' => $eventId,
            'target_url' => $targetUrl,
            'needs_update' => $needsUpdate,
            'message' => $message,
        ];
    }

    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $userInfo = isset($parts['user']) ? (string) $parts['user'] : '';
        if (isset($parts['pass'])) {
            $userInfo .= ':'.$parts['pass'];
        }
        if ($userInfo !== '') {
            $userInfo .= '@';
        }
        $port = isset($parts['port'])
            && ! (($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80))
                ? ':'.$parts['port']
                : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$userInfo}{$host}{$port}{$path}{$query}{$fragment}";
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
