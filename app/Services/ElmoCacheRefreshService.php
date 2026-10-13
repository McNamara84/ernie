<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EditorContext;
use App\Exceptions\ElmoCacheRefreshException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ElmoCacheRefreshService
{
    // Complete refresh contract in ELMO PR #1270, commit 67081b8bbe3af868822ecafb6a3c28dfac64a690.
    private const REQUIRED_CACHE_NAMES = [
        'resourceTypes', 'titleTypes', 'descriptionTypes', 'languages',
        'contributorPersonRoles', 'contributorInstitutionRoles', 'relationTypes', 'identifierTypes',
        'pid4instInstruments', 'mslLabs', 'gcmdScienceKeywords', 'gcmdPlatforms', 'gcmdInstruments',
        'chronostratTimescale', 'gemet', 'thesauriAvailability',
    ];

    private const CACHE_NAMES = [
        ...self::REQUIRED_CACHE_NAMES, 'dateTypes', 'licenses', 'pidAvailability', 'rorAffiliations',
        'raidProjects', 'mslKeywords', 'analyticalMethods', 'euroSciVoc', 'simpleLithology',
    ];

    public function isEnabled(?EditorContext $editor = null): bool
    {
        return (bool) config('elmo.enabled', false)
            && $editor !== EditorContext::ERNIE
            && ($editor !== EditorContext::ELMO_MSL || (bool) config('elmo.targets.elmo-msl.ready', false));
    }

    public function isConfigured(EditorContext $editor): bool
    {
        if ($editor === EditorContext::ERNIE) {
            return false;
        }

        $url = (string) config("elmo.targets.{$editor->value}.base_url", '');
        $key = (string) config("elmo.targets.{$editor->value}.api_key", '');
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && trim($key) !== ''
            && ! str_contains($key, "\r") && ! str_contains($key, "\n")
            && $this->timeoutsAreValid();
    }

    public function refresh(EditorContext $editor): void
    {
        if (! $this->isEnabled($editor)) {
            throw new ElmoCacheRefreshException('ELMO cache refresh target is not enabled.');
        }

        if (! $this->isConfigured($editor)) {
            throw new ElmoCacheRefreshException('ELMO cache refresh configuration is missing or invalid.');
        }

        $url = rtrim((string) config("elmo.targets.{$editor->value}.base_url"), '/').'/api/v2/admin/cache/refresh';

        try {
            $response = Http::acceptJson()
                ->withHeaders(['X-API-KEY' => (string) config("elmo.targets.{$editor->value}.api_key")])
                ->withoutRedirecting()
                ->connectTimeout((int) config('elmo.connect_timeout'))
                ->timeout((int) config('elmo.timeout'))
                ->post($url);
        } catch (ConnectionException) {
            throw new ElmoCacheRefreshException('ELMO cache refresh connection failed or timed out.', retryable: true);
        } catch (Throwable) {
            throw new ElmoCacheRefreshException('ELMO cache refresh request could not be sent.');
        }

        $status = $response->status();
        $body = $response->json();
        if ($status !== 200) {
            $failed = is_array($body) && is_array($body['failed'] ?? null)
                ? array_values(array_intersect(self::CACHE_NAMES, array_filter($body['failed'], is_string(...))))
                : [];
            $retryAfter = null;
            if ($status === 429) {
                $header = $response->header('Retry-After');
                $timestamp = preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $header)
                    ? strtotime($header) : false;
                $seconds = ctype_digit($header) ? (int) $header : ($timestamp === false ? 0 : $timestamp - time());
                if ($seconds > 0) {
                    $retryAfter = min(300, $seconds);
                }
            }

            throw new ElmoCacheRefreshException(
                "ELMO cache refresh returned HTTP {$status}.",
                retryable: in_array($status, [408, 429], true) || ($status >= 500 && $status < 600),
                status: $status,
                retryAfter: $retryAfter,
                failedCaches: $failed,
            );
        }

        $results = is_array($body) ? ($body['results'] ?? null) : null;
        if (! is_array($body) || ($body['success'] ?? null) !== true || ! empty($body['failed']) || ! is_array($results)
            || $results === [] || array_is_list($results)
            || array_diff(self::REQUIRED_CACHE_NAMES, array_keys($results)) !== []
            || array_any($results, static fn (mixed $ok): bool => $ok !== true)) {
            throw new ElmoCacheRefreshException('ELMO cache refresh returned an invalid success response.', status: $status);
        }
    }

    private function timeoutsAreValid(): bool
    {
        $connect = (int) config('elmo.connect_timeout');
        $http = (int) config('elmo.timeout');
        $job = (int) config('elmo.job_timeout');
        $lease = (int) config('queue.connections.elmo.retry_after');

        return $connect > 0 && $connect <= $http && $http < $job && $job < $lease;
    }
}
