<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Service for fetching data from the GEMET REST API.
 *
 * Handles retrieval of SuperGroups, Groups, and Concepts from the
 * European Environment Agency's GEMET thesaurus API.
 */
class GemetApiService
{
    private const BASE_URL = 'https://www.eionet.europa.eu/gemet/';

    private const SUPERGROUP_URI = 'http://www.eionet.europa.eu/gemet/supergroup/';

    private const GROUP_URI = 'http://www.eionet.europa.eu/gemet/group/';

    private const RELATION_BROADER = 'http://www.w3.org/2004/02/skos/core#broader';

    private const RELATION_GROUP_MEMBER = 'http://www.eionet.europa.eu/gemet/2004/06/gemet-schema.rdf#groupMember';

    private const CHECK_CONCURRENCY = 4;

    private const CHECK_BUDGET_SECONDS = 90;

    private const CHECK_REQUEST_SECONDS = 30;

    private const CHECK_CONNECT_SECONDS = 10;

    private const CHECK_ATTEMPTS = 2;

    private const CHECK_RETRY_SECONDS = 0.5;

    /**
     * Fetch a complete hierarchy count within the synchronous settings budget.
     * Download commands deliberately retain their existing request policy.
     */
    public function fetchHierarchyNodeCountForUpdateCheck(): int
    {
        $started = $this->checkTime();
        $deadline = $started + self::CHECK_BUDGET_SECONDS;
        $superGroups = $this->fetchCheckBatch(
            [self::SUPERGROUP_URI => ['thesaurus_uri' => self::SUPERGROUP_URI]],
            'getTopmostConcepts', 'supergroups', $started, $deadline,
        );
        $groups = $this->fetchCheckBatch(
            [self::GROUP_URI => ['thesaurus_uri' => self::GROUP_URI]],
            'getTopmostConcepts', 'groups', $started, $deadline,
        )[self::GROUP_URI];
        $count = count($superGroups[self::SUPERGROUP_URI]) + count($groups);

        foreach (array_chunk($groups, self::CHECK_CONCURRENCY) as $batch) {
            $queries = [];
            foreach ($batch as $group) {
                $queries[$group['uri']] = [
                    'concept_uri' => $group['uri'],
                    'relation_uri' => self::RELATION_GROUP_MEMBER,
                ];
            }

            foreach ($this->fetchCheckBatch($queries, 'getRelatedConcepts', 'members', $started, $deadline) as $members) {
                $count += count($members);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, array<string, string>>  $queries
     * @return array<string, array<int, array{uri: string, label: string, definition: string}>>
     */
    private function fetchCheckBatch(array $queries, string $endpoint, string $phase, float $started, float $deadline): array
    {
        $results = [];
        for ($attempt = 1; ; $attempt++) {
            $timeout = $this->checkTimeout($deadline, $started, $phase, $attempt);
            $responses = Http::pool(function (Pool $pool) use ($queries, $endpoint, $timeout): array {
                $requests = [];
                foreach ($queries as $uri => $query) {
                    $requests[] = $pool->as($uri)
                        ->connectTimeout(min(self::CHECK_CONNECT_SECONDS, $timeout))
                        ->timeout($timeout)
                        ->withoutRedirecting()
                        ->acceptJson()
                        ->get(self::BASE_URL.$endpoint, [...$query, 'language' => 'en']);
                }

                return $requests;
            }, concurrency: self::CHECK_CONCURRENCY);

            $this->checkTimeout($deadline, $started, $phase, $attempt);
            $pending = [];
            $delay = self::CHECK_RETRY_SECONDS;
            foreach ($queries as $uri => $query) {
                $response = $responses[$uri];
                if ($response instanceof Throwable && ! $response instanceof ConnectionException) {
                    throw $response;
                }

                if ($response instanceof Response && $response->successful()) {
                    try {
                        $results[$uri] = $this->parseCheckResponse($response, $phase === 'members');
                    } catch (RuntimeException|JsonException $exception) {
                        $this->failCheck('invalid_response', $phase, $uri, $attempt, $started, $exception);
                    }

                    continue;
                }

                $retryable = $response instanceof ConnectionException
                    || in_array($response->status(), [408, 429, 500, 502, 503, 504], true);
                $exception = $response instanceof ConnectionException ? $response
                    : ($response->toException() ?? new RuntimeException("Unexpected GEMET HTTP status {$response->status()}."));
                if (! $retryable || $attempt === self::CHECK_ATTEMPTS) {
                    $this->failCheck('unavailable', $phase, $uri, $attempt, $started, $exception);
                }

                $pending[$uri] = $query;
                if ($response instanceof Response && in_array($response->status(), [429, 503], true)) {
                    $delay = max($delay, $this->checkRetryAfter($response));
                }
            }

            if ($pending === []) {
                $this->checkTimeout($deadline, $started, $phase, $attempt);

                return $results;
            }

            if ($delay >= $deadline - $this->checkTime()) {
                $this->failCheck('budget_exhausted', $phase, (string) array_key_first($pending), $attempt, $started);
            }

            $this->waitForCheckRetry($delay);
            $queries = $pending;
        }
    }

    /** @return array<int, array{uri: string, label: string, definition: string}> */
    private function parseCheckResponse(Response $response, bool $allowEmpty): array
    {
        $body = $response->body();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data === [] && ! $allowEmpty)) {
            throw new RuntimeException('Expected GEMET entities.');
        }

        if (! str_starts_with(ltrim($body), '[')) {
            if (! isset($data['uri'])) {
                throw new RuntimeException('Unexpected GEMET object.');
            }
            $data = [$data];
        }

        foreach ($data as $entity) {
            if (! is_array($entity) || ! is_string($entity['uri'] ?? null) || trim($entity['uri']) === '') {
                throw new RuntimeException('Invalid GEMET entity URI.');
            }
        }

        return $this->parseEntities($data);
    }

    private function checkRetryAfter(Response $response): float
    {
        $value = trim($response->header('Retry-After'));
        if (ctype_digit($value)) {
            return (float) $value;
        }

        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new \DateTimeZone('GMT'));

        return $date === false ? 0 : max(0, $date->getTimestamp() - Date::now()->getTimestamp());
    }

    private function checkTimeout(float $deadline, float $started, string $phase, int $attempt): float
    {
        // Sub-millisecond values would round to an unlimited cURL timeout.
        $remaining = floor(($deadline - $this->checkTime()) * 1000) / 1000;
        if ($remaining < 0.001) {
            $this->failCheck('budget_exhausted', $phase, null, $attempt, $started);
        }

        return min(self::CHECK_REQUEST_SECONDS, $remaining);
    }

    private function failCheck(string $reason, string $phase, ?string $uri, int $attempt, float $started, ?Throwable $previous = null): never
    {
        Log::warning('GEMET update check failed.', [
            'reason' => $reason,
            'phase' => $phase,
            'uri' => $uri,
            'attempts' => $attempt,
            'elapsed_seconds' => $this->checkTime() - $started,
            'exception' => $previous,
        ]);

        throw new RuntimeException(match ($reason) {
            'budget_exhausted' => 'GEMET update check exceeded its time limit. Please try again later.',
            'invalid_response' => 'GEMET returned an invalid response. Please try again later.',
            default => 'GEMET could not be reached. Please try again later.',
        }, previous: $previous);
    }

    protected function checkTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    protected function waitForCheckRetry(float $seconds): void
    {
        usleep((int) ceil($seconds * 1_000_000));
    }

    /**
     * Fetch all GEMET SuperGroups.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchSuperGroups(string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getTopmostConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'thesaurus_uri' => self::SUPERGROUP_URI,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch GEMET SuperGroups: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Unexpected GEMET API response format for SuperGroups');
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch all GEMET Groups.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchGroups(string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getTopmostConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'thesaurus_uri' => self::GROUP_URI,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch GEMET Groups: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Unexpected GEMET API response format for Groups');
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch the SuperGroup parent for each Group.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, string> Map of group URI => supergroup URI
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchGroupToSuperGroupMapping(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $mapping = [];

        foreach ($groups as $group) {
            $url = self::BASE_URL.'getRelatedConcepts';

            $response = Http::timeout($timeout)
                ->accept('application/json')
                ->get($url, [
                    'concept_uri' => $group['uri'],
                    'relation_uri' => self::RELATION_BROADER,
                    'language' => $language,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch broader concepts for group {$group['uri']}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (is_array($data) && count($data) > 0) {
                // GEMET API returns a single object when there is exactly one
                // related concept, or a numeric array when there are multiple.
                $firstParent = array_is_list($data) ? $data[0] : $data;
                $parentUri = $firstParent['uri'] ?? null;
                if (is_string($parentUri) && $parentUri !== '') {
                    // The GEMET API returns broader relations with the group/
                    // URI prefix even when the target is a SuperGroup.
                    // Normalize to the supergroup/ namespace so the hierarchy
                    // builder can match Groups to their parent SuperGroups.
                    $parentUri = $this->normalizeSuperGroupUri($parentUri);
                    $mapping[$group['uri']] = $parentUri;
                }
            }
        }

        return $mapping;
    }

    /**
     * Fetch all concepts belonging to a specific Group.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchConceptsForGroup(string $groupUri, string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getRelatedConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'concept_uri' => $groupUri,
                'relation_uri' => self::RELATION_GROUP_MEMBER,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch concepts for group {$groupUri}: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException("Unexpected GEMET API response format for group members: {$groupUri}");
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch all concepts organized by group.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, array<int, array{uri: string, label: string, definition: string}>> Map of group URI => concepts
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchAllConceptsByGroup(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $conceptsByGroup = [];

        foreach ($groups as $group) {
            $conceptsByGroup[$group['uri']] = $this->fetchConceptsForGroup(
                $group['uri'],
                $language,
                $timeout
            );
        }

        return $conceptsByGroup;
    }

    /**
     * Fetch concept counts per group concurrently using Http::pool().
     *
     * Unlike {@see fetchAllConceptsByGroup()}, this method only returns the count
     * of concepts per group (not the full data), using concurrent HTTP requests
     * for better performance when only counts are needed.
     *
     * @param  array<int, array{uri: string}>  $groups
     * @return array<string, int> Map of group URI => concept count
     *
     * @throws RuntimeException If any API request fails
     */
    public function fetchConceptCountsByGroup(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $results = $this->fetchRelatedConceptsPooled($groups, $language, $timeout);

        $counts = [];
        foreach ($results as $groupUri => $data) {
            $counts[$groupUri] = is_array($data) ? count($this->parseEntities($data)) : 0;
        }

        return $counts;
    }

    /**
     * Fetch all concepts organized by group using concurrent HTTP requests.
     *
     * Uses Http::pool() for parallel fetching, significantly faster than
     * the sequential {@see fetchAllConceptsByGroup()} method.
     *
     * @param  array<int, array{uri: string}>  $groups
     * @return array<string, array<int, array{uri: string, label: string, definition: string}>> Map of group URI => concepts
     *
     * @throws RuntimeException If any API request fails
     */
    public function fetchAllConceptsByGroupConcurrently(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $results = $this->fetchRelatedConceptsPooled($groups, $language, $timeout);

        $conceptsByGroup = [];
        foreach ($results as $groupUri => $data) {
            if (! is_array($data)) {
                throw new RuntimeException("Unexpected GEMET API response format for group members: {$groupUri}");
            }

            $conceptsByGroup[$groupUri] = $this->parseEntities($data);
        }

        return $conceptsByGroup;
    }

    /**
     * Fetch related concepts for multiple groups using concurrent HTTP requests.
     *
     * Shared pool logic used by both {@see fetchConceptCountsByGroup()} and
     * {@see fetchAllConceptsByGroupConcurrently()}.
     *
     * @param  array<int, array{uri: string}>  $groups
     * @return array<string, mixed> Map of group URI => decoded JSON response
     *
     * @throws RuntimeException If any API request fails
     */
    private function fetchRelatedConceptsPooled(array $groups, string $language, int $timeout): array
    {
        $url = self::BASE_URL.'getRelatedConcepts';
        $groupUris = array_map(fn (array $group): string => $group['uri'], $groups);

        $responses = Http::pool(function (Pool $pool) use ($url, $groupUris, $language, $timeout): array {
            $requests = [];
            foreach ($groupUris as $groupUri) {
                $requests[] = $pool->timeout($timeout)
                    ->accept('application/json')
                    ->get($url, [
                        'concept_uri' => $groupUri,
                        'relation_uri' => self::RELATION_GROUP_MEMBER,
                        'language' => $language,
                    ]);
            }

            return $requests;
        });

        $results = [];
        foreach ($groupUris as $index => $groupUri) {
            $response = $responses[$index];

            if ($response instanceof Throwable) {
                throw new RuntimeException(
                    "HTTP request failed for group {$groupUri}: {$response->getMessage()}"
                );
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch concepts for group {$groupUri}: HTTP {$response->status()}"
                );
            }

            $results[$groupUri] = $response->json();
        }

        return $results;
    }

    /**
     * Parse API response entities into a normalized format.
     *
     * The GEMET API returns a numeric array of objects when there are multiple
     * results, but a single associative object when there is exactly one result.
     * This method normalizes both formats.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int, array{uri: string, label: string, definition: string}>
     */
    private function parseEntities(array $data): array
    {
        // GEMET API returns a single object (associative array) for exactly one
        // result. Wrap it in a list so the loop below processes it correctly.
        if (! array_is_list($data) && isset($data['uri'])) {
            $data = [$data];
        }

        $entities = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $uri = $item['uri'] ?? '';
            if (! is_string($uri) || $uri === '') {
                continue;
            }

            $label = '';
            if (isset($item['preferredLabel']['string']) && is_string($item['preferredLabel']['string'])) {
                $label = $item['preferredLabel']['string'];
            }

            $definition = '';
            if (isset($item['definition']['string']) && is_string($item['definition']['string'])) {
                $definition = $item['definition']['string'];
            }

            $entities[] = [
                'uri' => $uri,
                'label' => $label,
                'definition' => $definition,
            ];
        }

        return $entities;
    }

    /**
     * Normalize a group/ URI to a supergroup/ URI.
     *
     * The GEMET REST API returns broader relations with the group/ namespace
     * even when the referenced entity is actually a SuperGroup. This method
     * replaces the group/ prefix with supergroup/ so the URIs can be matched
     * against the SuperGroup URIs returned by getTopmostConcepts.
     */
    private function normalizeSuperGroupUri(string $uri): string
    {
        if (str_starts_with($uri, self::GROUP_URI)) {
            return self::SUPERGROUP_URI.substr($uri, strlen(self::GROUP_URI));
        }

        return $uri;
    }
}
