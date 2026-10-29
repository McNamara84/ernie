<?php

declare(strict_types=1);

namespace App\Services\B2inst;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/** Public B2INST v3 reads; never forwards credentials or follows unchecked redirects. */
class B2instClient
{
    private float $deadline;

    public function __construct()
    {
        $this->startBudget();
    }

    public function startBudget(): void
    {
        $this->deadline = microtime(true) + min(1100, max(1, (int) config('b2inst.max_duration', 900)));
    }

    public function remainingSeconds(): float
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('B2INST synchronization time budget exhausted.');
        }

        return $remaining;
    }

    public function host(): string
    {
        return rtrim((string) config('b2inst.host'), '/');
    }

    public function isRegistryUrl(string $url): bool
    {
        $parts = parse_url($url);
        $configured = parse_url($this->host());
        if ($parts === false || $configured === false) {
            return false;
        }
        $aliases = ['b2inst.pid.gwdg.de', 'b2inst.gwdg.de'];
        $host = strtolower($parts['host'] ?? '');
        $configuredHost = strtolower($configured['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment'])
            && ($parts['port'] ?? 443) === ($configured['port'] ?? 443)
            && ($host === $configuredHost || (in_array($host, $aliases, true) && in_array($configuredHost, $aliases, true)));
    }

    private function initialUrl(int $size): string
    {
        if ($size < 1 || $size > 1000 || ! $this->isRegistryUrl($this->host()) || ! in_array(parse_url($this->host(), PHP_URL_PATH), [null, '', '/'], true) || parse_url($this->host(), PHP_URL_QUERY) !== null) {
            throw new RuntimeException('Invalid B2INST host or page size configuration.');
        }

        return $this->host().'/api/records?'.http_build_query(['page' => 1, 'size' => $size, 'sort' => 'newest', 'allversions' => 'false']);
    }

    public function count(): int
    {
        return $this->page($this->initialUrl(1))['total'];
    }

    /** @return array{records: list<array<string, mixed>>, total: int} */
    public function records(): array
    {
        try {
            return $this->readSnapshot();
        } catch (B2instSnapshotChanged) {
            return $this->readSnapshot();
        }
    }

    /** @return array{records: list<array<string, mixed>>, total: int} */
    private function readSnapshot(): array
    {
        $size = (int) config('b2inst.page_size', 100);
        $initial = $this->initialUrl($size);
        $url = $initial;
        $records = [];
        $visited = [];
        $total = null;
        $firstSignature = null;
        do {
            $this->remainingSeconds();
            if (isset($visited[$url]) || count($visited) >= (int) config('b2inst.max_pages', 1000)) {
                throw new RuntimeException('B2INST pagination loop or page limit reached.');
            }
            $visited[$url] = true;
            $page = $this->page($url);
            $firstSignature ??= $this->pageSignature($page);
            $total ??= $page['total'];
            if ($total !== $page['total']) {
                throw new B2instSnapshotChanged('B2INST total changed during pagination.');
            }
            if ($total > (int) config('b2inst.max_records', 100000)) {
                throw new RuntimeException('B2INST record limit reached.');
            }
            foreach ($page['records'] as $record) {
                $id = $record['id'] ?? null;
                if (! is_string($id) || $id === '') {
                    throw new RuntimeException('B2INST record has no valid ID.');
                }
                if (isset($records[$id])) {
                    throw new B2instSnapshotChanged('B2INST returned a duplicate record ID: '.$id);
                }
                $records[$id] = $record;
            }
            if (count($records) > $total || ($page['records'] === [] && ($total > 0 || $page['next'] !== null))) {
                throw new B2instSnapshotChanged('B2INST pagination made no progress or exceeded its total.');
            }
            $url = $page['next'] === null ? null : $this->nextUrl($page['next'], $url, $size, count($visited) + 1);
        } while ($url !== null);

        if (count($records) !== $total) {
            throw new B2instSnapshotChanged('B2INST pagination ended before all records were received.');
        }
        // Detect movement at the search boundary, including same-count revision changes.
        // The API does not offer snapshot isolation for all intermediate records.
        if ($firstSignature !== $this->pageSignature($this->page($initial))) {
            throw new B2instSnapshotChanged('B2INST first page changed during synchronization.');
        }

        return ['records' => array_values($records), 'total' => $total];
    }

    private function nextUrl(string $link, string $base, int $size, int $page): string
    {
        $url = (string) UriResolver::resolve(new Uri($base), new Uri($link));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (! $this->isRegistryUrl($url) || parse_url($url, PHP_URL_PATH) !== '/api/records'
            || ($query['page'] ?? null) !== (string) $page || ($query['size'] ?? null) !== (string) $size
            || ($query['sort'] ?? null) !== 'newest' || ! is_string($query['allversions'] ?? null)
            || strtolower($query['allversions']) !== 'false'
            || array_diff(array_keys($query), ['page', 'size', 'sort', 'allversions']) !== []) {
            throw new RuntimeException('Unsafe or scope-changing B2INST pagination link.');
        }

        return $url;
    }

    /** @return array{records: list<array<string, mixed>>, total: int, next: string|null} */
    private function page(string $url): array
    {
        $data = $this->json($url);
        $total = data_get($data, 'hits.total');
        $hits = data_get($data, 'hits.hits');
        $next = data_get($data, 'links.next');
        if (! is_int($total) || $total < 0 || ! is_array($hits) || ! array_is_list($hits)
            || ($next !== null && (! is_string($next) || $next === ''))) {
            throw new RuntimeException('Invalid B2INST v3 search response.');
        }
        foreach ($hits as $hit) {
            if (! is_array($hit) || array_is_list($hit)) {
                throw new RuntimeException('Invalid B2INST record object.');
            }
        }

        return ['records' => $hits, 'total' => $total, 'next' => $next];
    }

    /** @param array{records: list<array<string, mixed>>, total: int, next: string|null} $page */
    private function pageSignature(array $page): string
    {
        return hash('sha256', json_encode([$page['total'], array_map(static fn (array $record): array => [
            $record['id'] ?? null, $record['revision_id'] ?? null, $record['updated'] ?? null,
        ], $page['records'])], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve only registry record targets; never fetch an arbitrary Handle destination.
     *
     * @return array<string, mixed>|null
     */
    public function recordAt(string $target): ?array
    {
        if (! $this->isRegistryUrl($target) || parse_url($target, PHP_URL_QUERY) !== null
            || ! preg_match('~^/(?:api/)?records/([a-zA-Z0-9-]+)$~D', (string) parse_url($target, PHP_URL_PATH), $match)) {
            return null;
        }
        $parts = parse_url($target);
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $this->json($origin.'/api/records/'.$match[1]);
    }

    /** @return array<string, mixed> */
    public function json(string $url, bool $allowNotFound = false): array
    {
        $response = $this->request($url);
        if (! $response->successful() && ! ($allowNotFound && $response->status() === 404)) {
            throw new RuntimeException('Failed to fetch from b2inst API or Handle resolver: HTTP '.$response->status());
        }
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)
            || ($response->status() === 404 && ($json['responseCode'] ?? null) !== 100)) {
            throw new RuntimeException('Invalid JSON object from B2INST or Handle resolver.');
        }

        return $json;
    }

    private function request(string $url): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = null;
            try {
                $response = Http::acceptJson()->withoutRedirecting()
                    ->connectTimeout(min(max(1, (float) config('b2inst.connect_timeout', 10)), $this->remainingSeconds()))
                    ->timeout(min(max(1, (float) config('b2inst.timeout', 30)), $this->remainingSeconds()))
                    ->get($url);
                if (! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === 3) {
                    return $response;
                }
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw new RuntimeException('B2INST or Handle resolver unavailable.', previous: $exception);
                }
            }
            $delay = (float) config('b2inst.retry_delay', 1) * $attempt;
            $retryAfter = $response?->header('Retry-After');
            if (is_string($retryAfter) && $retryAfter !== '') {
                $seconds = ctype_digit($retryAfter) ? (float) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());
                $delay = max($delay, $seconds);
            }
            if ($delay >= $this->remainingSeconds()) {
                throw new RuntimeException('Retry-After exceeds the B2INST time budget.');
            }
            if ($delay > 0) {
                Sleep::usleep((int) ($delay * 1000000));
            }
        }

        throw new RuntimeException('B2INST request failed.');
    }
}
