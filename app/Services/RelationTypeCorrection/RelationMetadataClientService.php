<?php

declare(strict_types=1);

namespace App\Services\RelationTypeCorrection;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Reads registration metadata without the lossy citation transformers. */
class RelationMetadataClientService
{
    use ChecksCacheTagging;

    public function __construct(private readonly RelationSupplementaryClientService $supplementary) {}

    /** @return array{evidence: list<RelationEvidence>, complete: bool, sources: list<array<string, mixed>>} */
    public function forPair(string $subject, string $object): array
    {
        $evidence = [];
        $sources = [];
        $complete = true;
        foreach (array_unique([$subject, $object]) as $doi) {
            foreach (['datacite', 'crossref'] as $provider) {
                $record = $this->record($provider, $doi);
                $sources[] = ['provider' => $provider, 'doi' => $doi, 'status' => $record['status'], 'fetched_at' => $record['fetched_at']];
                $complete = $complete && in_array($record['status'], ['ok', 'not_found'], true);
                if ($record['status'] !== 'ok') {
                    continue;
                }
                foreach ($this->parse($provider, $doi, $record['metadata'], $record['fetched_at']) as $claim) {
                    if (($claim->subject === $subject && $claim->object === $object) || ($claim->subject === $object && $claim->object === $subject)) {
                        $evidence[] = $claim;
                    }
                }
            }
        }

        // Supplementary outages and removed event categories never turn primary
        // metadata into negative evidence or establish confidence independently.
        if ($complete) {
            $support = $this->supplementary->forPair($subject, $object);
            array_push($evidence, ...$support['evidence']);
            array_push($sources, ...$support['sources']);
        }

        return compact('evidence', 'complete', 'sources');
    }

    /** @return array{status: string, metadata: array<string, mixed>, fetched_at: string} */
    public function record(string $provider, string $doi): array
    {
        $cacheKey = CacheKey::RELATION_CORRECTION_RAW;
        $cache = $this->getCacheInstance($cacheKey->tags());
        $key = $cacheKey->key($provider.':'.hash('sha256', $doi));
        /** @var array{status: string, metadata: array<string, mixed>, fetched_at: string}|null $cached */
        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $result = ['status' => 'unavailable', 'metadata' => [], 'fetched_at' => now()->toIso8601String()];
        try {
            $url = $this->url($provider, $doi);
            $request = Http::acceptJson()->timeout(10)->connectTimeout(5)->retry(2, 100,
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->status() === 429 || $exception->response->serverError())),
                throw: false);
            if ($provider === 'crossref' && config('crossref.mailto')) {
                $request = $request->withHeaders(['User-Agent' => 'ERNIE relation correction (mailto:'.config('crossref.mailto').')']);
            }
            $response = $request->get($url);
            if ($response->status() === 404) {
                $result['status'] = 'not_found';
            } elseif ($response->successful()) {
                $metadata = $response->json($provider === 'datacite' ? 'data.attributes' : 'message');
                $recordDoi = is_array($metadata) ? ($metadata[$provider === 'datacite' ? 'doi' : 'DOI'] ?? null) : null;
                // A truncated or mismatched record must not look like an empty record.
                $validCollections = is_array($metadata) && ($provider === 'datacite'
                    ? (! isset($metadata['relatedIdentifiers']) || is_array($metadata['relatedIdentifiers']))
                    : ((! isset($metadata['relation']) || is_array($metadata['relation'])) && (! isset($metadata['reference']) || is_array($metadata['reference']))));
                if ($validCollections && is_string($recordDoi) && RelationTypeRules::doi($recordDoi) === $doi) {
                    $result['status'] = 'ok';
                    $result['metadata'] = $metadata;
                } else {
                    $result['status'] = 'incomplete';
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        $cache->put($key, $result, $result['status'] === 'ok' || $result['status'] === 'not_found' ? $cacheKey->ttl() : 300);

        return $result;
    }

    public function url(string $provider, string $doi): string
    {
        return ($provider === 'datacite' ? 'https://api.datacite.org/dois/' : rtrim((string) config('crossref.base_url', 'https://api.crossref.org/works'), '/').'/').rawurlencode($doi);
    }

    /** @param array<string, mixed> $metadata
     * @return list<RelationEvidence>
     */
    public function parse(string $provider, string $doi, array $metadata, string $fetchedAt): array
    {
        $claims = [];
        if ($provider === 'datacite') {
            $related = $metadata['relatedIdentifiers'] ?? [];
            if (! is_array($related)) {
                return [];
            }
            foreach ($related as $index => $row) {
                if (! is_array($row) || ($row['relatedIdentifierType'] ?? null) !== 'DOI' || ! is_string($row['relatedIdentifier'] ?? null) || ! is_string($row['relationType'] ?? null)) {
                    continue;
                }
                $other = RelationTypeRules::doi($row['relatedIdentifier']);
                $type = RelationTypeRules::dataCite($row['relationType']);
                if ($other !== null && $other !== $doi && $type !== null) {
                    $claims[] = new RelationEvidence($provider, 'datacite:'.$doi, $doi, $doi, $other, $type, $type,
                        $this->url($provider, $doi), '/data/attributes/relatedIdentifiers/'.$index, $fetchedAt);
                }
            }
        } else {
            $relations = $metadata['relation'] ?? [];
            if (! is_array($relations)) {
                return [];
            }
            foreach ($relations as $name => $rows) {
                $type = is_string($name) ? RelationTypeRules::crossref($name) : null;
                if ($type === null || ! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $index => $row) {
                    if (! is_array($row) || ($row['id-type'] ?? null) !== 'doi' || ! is_string($row['id'] ?? null) || ! in_array($row['asserted-by'] ?? null, ['subject', 'object'], true)) {
                        continue;
                    }
                    $other = RelationTypeRules::doi($row['id']);
                    if ($other !== null && $other !== $doi) {
                        $claimant = $row['asserted-by'] === 'subject' ? $doi : $other;
                        $claims[] = new RelationEvidence($provider, 'crossref:'.$claimant, $claimant, $doi, $other, (string) $name, $type,
                            $this->url($provider, $doi), '/message/relation/'.$name.'/'.$index, $fetchedAt);
                    }
                }
            }
            // Bibliographic references are retained as supporting assertions only.
            $references = $metadata['reference'] ?? [];
            foreach (is_array($references) ? $references : [] as $index => $row) {
                $other = is_array($row) && is_string($row['DOI'] ?? null) ? RelationTypeRules::doi($row['DOI']) : null;
                if ($other !== null && $other !== $doi) {
                    $claims[] = new RelationEvidence($provider, 'crossref:'.$doi, $doi, $doi, $other, 'reference', 'Cites',
                        $this->url($provider, $doi), '/message/reference/'.$index, $fetchedAt, false);
                }
            }
        }

        return $claims;
    }
}
