<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Bounded Scholix/Event Data reads; they never justify a correction alone. */
final class RelationSupplementaryClientService
{
    use ChecksCacheTagging;

    /** @return array{evidence: list<RelationEvidence>, sources: list<array<string, mixed>>} */
    public function forPair(string $own, string $other): array
    {
        $evidence = [];
        $sources = [];
        $cacheKey = CacheKey::RELATION_CORRECTION_SUPPORT;
        $cache = $this->getCacheInstance($cacheKey->tags());
        foreach (['datacite_event_data', 'scholexplorer'] as $provider) {
            $key = $cacheKey->key($provider.':'.hash('sha256', $own));
            /** @var array{rows: list<array<string, mixed>>, status: string, fetched_at: string, url: string}|null $record */
            $record = $cache->get($key);
            if ($record === null) {
                $record = $this->fetch($provider, $own);
                $cache->put($key, $record, $record['status'] === 'ok' ? $cacheKey->ttl() : 300);
            }
            $sources[] = ['provider' => $provider, 'doi' => $own, 'status' => $record['status'], 'fetched_at' => $record['fetched_at']];
            foreach ($record['rows'] as $index => $row) {
                $claim = $this->parse($provider, $row, $record['url'], '/results/'.$index, $record['fetched_at']);
                if ($claim !== null && (($claim->subject === $own && $claim->object === $other) || ($claim->subject === $other && $claim->object === $own))) {
                    $evidence[] = $claim;
                }
            }
        }

        return compact('evidence', 'sources');
    }

    /** @return array{rows: list<array<string, mixed>>, status: string, fetched_at: string, url: string} */
    private function fetch(string $provider, string $doi): array
    {
        $url = $provider === 'datacite_event_data' ? 'https://api.datacite.org/events' : rtrim((string) config('scholexplorer.base_url'), '/').'/Links';
        $record = ['rows' => [], 'status' => 'unavailable', 'fetched_at' => now()->toIso8601String(), 'url' => $url];
        try {
            // Three pages, fixed endpoints, no following arbitrary next-page URLs.
            for ($page = 0; $page < 3; $page++) {
                $parameters = $provider === 'datacite_event_data'
                    ? ['doi' => $doi, 'page[size]' => 200, 'page[number]' => $page + 1, 'source-id' => 'datacite-related,datacite-crossref,crossref']
                    : ['sourcePid' => $doi, 'page' => $page, 'size' => 100];
                $response = Http::acceptJson()->timeout(10)->connectTimeout(5)->get($url, $parameters);
                $json = $response->json();
                $rows = is_array($json) ? ($json[$provider === 'datacite_event_data' ? 'data' : 'result'] ?? null) : null;
                if (! $response->successful() || ! is_array($rows)) {
                    $record['status'] = 'incomplete';
                    break;
                }
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $record['rows'][] = $row;
                    }
                }
                $more = $provider === 'datacite_event_data' ? ! empty($json['links']['next']) : (int) ($json['totalPages'] ?? 1) > $page + 1;
                $record['status'] = $more ? 'incomplete' : 'ok';
                if (! $more) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $record;
    }

    /** @param array<string, mixed> $row */
    public function parse(string $provider, array $row, string $url, string $pointer, string $fetchedAt): ?RelationEvidence
    {
        if ($provider === 'datacite_event_data') {
            $attributes = $row['attributes'] ?? null;
            if (! is_array($attributes)) {
                return null;
            }
            $subject = $attributes['subj-id'] ?? null;
            $object = $attributes['obj-id'] ?? null;
            $name = $attributes['relation-type-id'] ?? null;
            $source = $attributes['source-id'] ?? 'unknown';
        } else {
            $subject = $row['source']['Identifier'][0]['ID'] ?? null;
            $object = $row['target']['Identifier'][0]['ID'] ?? null;
            $name = $row['RelationshipType']['Name'] ?? null;
            $source = 'scholix-unverified-origin';
        }
        if (! is_string($subject) || ! is_string($object) || ! is_string($name) || ! is_string($source)) {
            return null;
        }
        $subjectDoi = RelationTypeRules::doi($subject);
        $objectDoi = RelationTypeRules::doi($object);
        $slug = RelationTypeRules::dataCite($name);
        if ($slug === null) {
            foreach (RelationTypeRules::vocabulary() as $type) {
                $kebab = mb_strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $type));
                if (mb_strtolower($name) === $kebab || mb_strtolower($name) === mb_strtolower($type)) {
                    $slug = $type;
                    break;
                }
            }
        }
        if ($slug === null || $subjectDoi === null || $objectDoi === null || $subjectDoi === $objectDoi) {
            return null;
        }

        return new RelationEvidence($provider, $source.':'.$subjectDoi, $subjectDoi, $subjectDoi, $objectDoi, $name, $slug, $url, $pointer, $fetchedAt, false);
    }
}
