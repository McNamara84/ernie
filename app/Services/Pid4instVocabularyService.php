<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @phpstan-type LookupIdentifier array{value: string, type: string, source: string}
 * @phpstan-type Instrument array{id?: string, pid: string, pidType: string, name: string, lookupIdentifiers?: list<LookupIdentifier>}
 * @phpstan-type Provenance array{metadataIdentifier?: array{identifierType?: string, identifierValue?: string}|null, providerPid?: string|null}
 * @phpstan-type Snapshot array{data: list<Instrument>, lastUpdated?: string, total?: int, _sync?: array{records?: array<string, Provenance>}}
 */
final class Pid4instVocabularyService
{
    public const FILE = 'pid4inst-instruments.json';

    public function __construct(private readonly VocabularyCacheService $cache) {}

    /** @return Snapshot */
    public function getLocalPayload(): array
    {
        $payload = $this->cache->cacheVocabulary(CacheKey::PID4INST_INSTRUMENTS, function (): array {
            if (! Storage::exists(self::FILE)) {
                throw new VocabularyNotFoundException('php artisan get-pid4inst-instruments');
            }
            $content = Storage::get(self::FILE);
            if (! is_string($content) || $content === '') {
                throw new VocabularyReadException;
            }

            return $this->decode($content);
        });

        // B2INST v3 keeps the original identifiers in snapshot provenance while
        // publishing the verified PID for new selections. Adapt on every read so
        // snapshots and caches created before name resolution work immediately.
        $provenanceRecords = $payload['_sync']['records'] ?? [];
        foreach ($payload['data'] as &$record) {
            $provenance = $provenanceRecords[$record['id'] ?? ''] ?? [];
            $metadata = $provenance['metadataIdentifier'] ?? null;
            $type = match (strtolower($metadata['identifierType'] ?? '')) {
                'handle' => 'Handle',
                'doi' => 'DOI',
                'url' => 'URL',
                default => null,
            };
            $identifiers = $record['lookupIdentifiers'] ?? [];
            if ($type !== null && is_string($metadata['identifierValue'] ?? null) && trim($metadata['identifierValue']) !== '') {
                $identifiers[] = ['value' => $metadata['identifierValue'], 'type' => $type, 'source' => 'metadata'];
            }
            if (is_string($provenance['providerPid'] ?? null) && trim($provenance['providerPid']) !== '') {
                $identifiers[] = ['value' => $provenance['providerPid'], 'type' => 'Handle', 'source' => 'epic'];
            }
            if ($identifiers !== []) {
                $record['lookupIdentifiers'] = array_values(array_unique($identifiers, SORT_REGULAR));
            }
        }
        unset($record);

        return $payload;
    }

    /** @return Snapshot */
    public function getPublicPayload(): array
    {
        $payload = array_intersect_key($this->getLocalPayload(), array_flip(['lastUpdated', 'total', 'data']));
        // Project on every read, including cache hits.
        $payload['data'] = array_map(function (array $record): array {
            unset($record['lookupIdentifiers']);

            return $record;
        }, $payload['data']);

        return $payload;
    }

    /** @return Snapshot */
    private function decode(string $content): array
    {
        try {
            $object = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new VocabularyCorruptedException($exception->getMessage());
        }
        if (! $object instanceof \stdClass || ! is_array($object->data ?? null)
            || ! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
            throw new VocabularyCorruptedException('Expected an instrument data list.');
        }
        foreach ($payload['data'] as $record) {
            if (! is_array($record) || ! is_string($record['pid'] ?? null)
                || ! is_string($record['pidType'] ?? null) || ! is_string($record['name'] ?? null)) {
                throw new VocabularyCorruptedException('Invalid instrument record.');
            }
            if (isset($record['lookupIdentifiers'])) {
                if (! is_array($record['lookupIdentifiers']) || ! array_is_list($record['lookupIdentifiers'])) {
                    throw new VocabularyCorruptedException('Invalid instrument lookup identifiers.');
                }
                foreach ($record['lookupIdentifiers'] as $identifier) {
                    if (! is_array($identifier) || ! is_string($identifier['value'] ?? null)
                        || ! is_string($identifier['type'] ?? null) || ! is_string($identifier['source'] ?? null)) {
                        throw new VocabularyCorruptedException('Invalid instrument lookup identifier.');
                    }
                }
            }
        }

        /** @var Snapshot $payload */
        return $payload;
    }

    /** Publish a fully validated local snapshot without exposing a partial write. */
    public function replace(string $json): void
    {
        $this->decode($json);
        $temporary = '.pid4inst-'.Str::uuid().'.tmp';
        try {
            if (! Storage::put($temporary, $json) || Storage::get($temporary) !== $json) {
                throw new VocabularyReadException;
            }
            if (! @rename(Storage::path($temporary), Storage::path(self::FILE))) {
                throw new \RuntimeException('Failed to replace the instrument registry.');
            }
        } finally {
            Storage::delete($temporary);
        }
        $this->cache->invalidateVocabularyCache(CacheKey::PID4INST_INSTRUMENTS);
    }
}
