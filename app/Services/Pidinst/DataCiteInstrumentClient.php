<?php

declare(strict_types=1);

namespace App\Services\Pidinst;

use App\Exceptions\PidinstSnapshotChanged;
use App\Services\DataCitePublicApiLimiterService;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

/** Complete anonymous Instrument discovery, sharing the application's public read budget. */
final class DataCiteInstrumentClient
{
    private float $deadline;

    public function __construct(private readonly DataCitePublicApiLimiterService $limiter)
    {
        $this->startBudget();
    }

    public function startBudget(?float $deadline = null): void
    {
        $this->deadline = $deadline ?? microtime(true) + min(1100, max(1, (int) (config('pidinst.max_duration') ?? config('b2inst.max_duration', 900))));
    }

    private function remainingSeconds(): float
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('PIDINST DataCite synchronization time budget exhausted.');
        }

        return $remaining;
    }

    public function host(): string
    {
        return rtrim((string) config('pidinst.datacite.host', 'https://api.datacite.org'), '/');
    }

    private function initialUrl(int $size): string
    {
        $parts = parse_url($this->host());
        if ($size < 1 || $size > 1000 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Invalid PIDINST DataCite host or page size configuration.');
        }

        return $this->host().'/dois?'.http_build_query(['resource-type-id' => 'instrument', 'page' => ['cursor' => '1', 'size' => $size]]);
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
        } catch (PidinstSnapshotChanged) {
            return $this->readSnapshot();
        }
    }

    /** @return array{records: list<array<string, mixed>>, total: int} */
    private function readSnapshot(): array
    {
        $size = (int) config('pidinst.datacite.page_size', 1000);
        $initial = $this->initialUrl($size);
        $url = $initial;
        $records = $visited = [];
        $total = $signature = null;
        do {
            if (isset($visited[$url]) || count($visited) >= (int) config('pidinst.datacite.max_pages', 1000)) {
                throw new RuntimeException('DataCite pagination loop or page limit reached.');
            }
            $visited[$url] = true;
            $page = $this->page($url);
            $total ??= $page['total'];
            $signature ??= $this->signature($page);
            if ($total !== $page['total']) {
                throw new PidinstSnapshotChanged('DataCite total changed during pagination.');
            }
            if ($total > (int) config('pidinst.datacite.max_records', 100000)) {
                throw new RuntimeException('DataCite record limit reached.');
            }
            foreach ($page['records'] as $record) {
                $doi = DataCiteInstrumentNormalizer::doi($record['id']);
                if (isset($records[$doi])) {
                    throw new PidinstSnapshotChanged('DataCite returned a duplicate DOI: '.$doi);
                }
                $records[$doi] = $record;
            }
            if (count($records) > $total || ($page['records'] === [] && (count($records) < $total || $page['next'] !== null))) {
                throw new PidinstSnapshotChanged('DataCite pagination made no progress or exceeded its total.');
            }
            $url = $page['next'] === null ? null : $this->nextUrl($page['next'], $url, $size);
        } while ($url !== null);
        if (count($records) !== $total) {
            throw new PidinstSnapshotChanged('DataCite pagination ended before all records were received.');
        }
        if ($signature !== $this->signature($this->page($initial))) {
            throw new PidinstSnapshotChanged('DataCite first page changed during synchronization.');
        }

        return ['records' => array_values($records), 'total' => $total];
    }

    private function nextUrl(string $link, string $base, int $size): string
    {
        $url = (string) UriResolver::resolve(new Uri($base), new Uri($link));
        $parts = parse_url($url);
        $host = parse_url($this->host());
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== strtolower($host['host'] ?? '')
            || ($parts['port'] ?? 443) !== ($host['port'] ?? 443) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/dois' || ($query['resource-type-id'] ?? null) !== 'instrument'
            || ! is_array($page) || ($page['size'] ?? null) !== (string) $size || ! is_string($page['cursor'] ?? null) || $page['cursor'] === ''
            || array_diff(array_keys($query), ['resource-type-id', 'page']) !== [] || array_diff(array_keys($page), ['size', 'cursor']) !== []) {
            throw new RuntimeException('Unsafe or scope-changing DataCite pagination link.');
        }

        return $url;
    }

    /** @return array{records: list<array<string, mixed>>, total: int, next: string|null} */
    private function page(string $url): array
    {
        $data = $this->request($url);
        $total = data_get($data, 'meta.total');
        $records = $data['data'] ?? null;
        $next = data_get($data, 'links.next');
        if (! is_int($total) || $total < 0 || ! is_array($records) || ! array_is_list($records)
            || ($next !== null && (! is_string($next) || $next === ''))) {
            throw new RuntimeException('Invalid DataCite instrument search response.');
        }
        foreach ($records as $record) {
            try {
                if (! is_array($record) || ($record['type'] ?? null) !== 'dois'
                    || ! is_array($record['attributes'] ?? null) || data_get($record, 'attributes.state') !== 'findable'
                    || data_get($record, 'attributes.types.resourceTypeGeneral') !== 'Instrument'
                    || DataCiteInstrumentNormalizer::doi($record['id'] ?? null) !== DataCiteInstrumentNormalizer::doi(data_get($record, 'attributes.doi'))) {
                    throw new InvalidArgumentException('Unexpected record identity, state or resource type.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Invalid DataCite Instrument search record: '.$exception->getMessage(), previous: $exception);
            }
        }

        return ['records' => $records, 'total' => $total, 'next' => $next];
    }

    /** @param array{records: list<array<string, mixed>>, total: int, next: string|null} $page */
    private function signature(array $page): string
    {
        return hash('sha256', json_encode([$page['total'], array_map(static fn (array $record): array => [
            $record['id'], data_get($record, 'attributes.updated'), data_get($record, 'attributes.metadataVersion'),
        ], $page['records'])], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function request(string $url): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->remainingSeconds();
            if (! $this->limiter->tryAcquire()) {
                throw new RuntimeException('DataCite public API read budget or cooldown is active; retry the PIDINST update later.');
            }
            $response = null;
            try {
                $response = Http::accept('application/vnd.api+json')->withoutRedirecting()->withUserAgent($this->limiter->userAgent())
                    ->connectTimeout(min(max(1, (float) config('pidinst.datacite.connect_timeout', 10)), $this->remainingSeconds()))
                    ->timeout(min(max(1, (float) config('pidinst.datacite.timeout', 30)), $this->remainingSeconds()))->get($url);
                $this->limiter->observe($response);
                if ($response->successful()) {
                    $json = $response->json();
                    if (! is_array($json) || array_is_list($json)) {
                        throw new RuntimeException('Invalid JSON object from DataCite.');
                    }

                    return $json;
                }
                if (! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === 3) {
                    throw new RuntimeException('Failed to fetch PIDINST instruments from DataCite: HTTP '.$response->status());
                }
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw new RuntimeException('DataCite instrument API unavailable.', previous: $exception);
                }
            }
            $delay = max((float) config('pidinst.datacite.retry_delay', 1) * $attempt, $this->limiter->retryAfterSeconds());
            $retryAfter = $response?->header('Retry-After');
            if (is_string($retryAfter) && $retryAfter !== '') {
                $delay = max($delay, ctype_digit($retryAfter) ? (float) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time()));
            }
            if ($delay >= $this->remainingSeconds()) {
                throw new RuntimeException('Retry-After exceeds the PIDINST DataCite time budget.');
            }
            if ($delay > 0) {
                Sleep::usleep((int) ($delay * 1000000));
            }
        }

        throw new RuntimeException('DataCite instrument request failed.');
    }
}
