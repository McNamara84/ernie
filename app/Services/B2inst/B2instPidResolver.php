<?php

declare(strict_types=1);

namespace App\Services\B2inst;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use InvalidArgumentException;
use RuntimeException;

class B2instPidResolver
{
    use ChecksCacheTagging;

    public function __construct(private readonly B2instClient $client) {}

    public static function handle(string $value): string
    {
        $value = trim($value);
        if (preg_match('~^https?://hdl\.handle\.net/(?:api/handles/)?([^?#]+)(?:\?[^#]*)?$~iD', $value, $match)) {
            $value = rawurldecode($match[1]);
        }
        if (! preg_match('~^[0-9]+(?:\.[A-Za-z0-9]+)*/[^\s?#]+$~uD', $value)) {
            throw new InvalidArgumentException('Invalid Handle identifier.');
        }

        return $value;
    }

    /** @param array<string, mixed> $record */
    public function resolve(string $metadata, ?string $provider, array $record): string
    {
        $metadata = self::handle($metadata);
        $provider = $provider === null ? null : self::handle($provider);
        if ($metadata === $provider) {
            return $metadata;
        }
        $metadataTarget = $this->target($metadata);
        if ($provider === null) {
            if ($metadataTarget === null) {
                throw new InvalidArgumentException('Metadata Handle is not registered and has no ePIC provider PID.');
            }
            if (! $this->belongsTo($metadataTarget, $record)) {
                throw new RuntimeException('Metadata Handle target does not establish the instrument identity.');
            }

            return $metadata;
        }
        $providerTarget = $this->target($provider);
        // A registered but unrelated target is a conflict, even if the other candidate matches.
        foreach ([$metadataTarget, $providerTarget] as $target) {
            if ($target !== null && ! $this->belongsTo($target, $record)) {
                throw new RuntimeException('Conflicting B2INST Handle targets for record '.$record['id']);
            }
        }
        if ($providerTarget !== null) {
            return $provider;
        }
        if ($metadataTarget !== null) {
            return $metadata;
        }

        throw new RuntimeException('Neither conflicting B2INST Handle is registered for record '.$record['id']);
    }

    private function target(string $handle): ?string
    {
        $cacheKey = CacheKey::B2INST_HANDLE;
        $key = $cacheKey->key(hash('sha256', $handle));
        $cache = $this->getCacheInstance($cacheKey->tags());
        /** @var array{target: string|null}|null $cached */
        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached['target'];
        }
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $handle)));
        $response = $this->client->json('https://hdl.handle.net/api/handles/'.$encoded, true);
        if (($response['responseCode'] ?? null) === 100) {
            $cache->put($key, ['target' => null], 60);

            return null;
        }
        if (($response['responseCode'] ?? null) !== 1 || ! is_array($response['values'] ?? null)) {
            throw new RuntimeException('Handle resolution could not be verified.');
        }
        $targets = [];
        foreach ($response['values'] as $value) {
            if (data_get($value, 'type') === 'URL') {
                $target = data_get($value, 'data.value');
                if (! is_string($target) || filter_var($target, FILTER_VALIDATE_URL) === false) {
                    throw new RuntimeException('Handle resolver returned an invalid URL.');
                }
                $targets[] = $target;
            }
        }
        $targets = array_values(array_unique($targets));
        if (count($targets) !== 1) {
            throw new RuntimeException('Handle resolution has no unambiguous URL target.');
        }
        $cache->put($key, ['target' => $targets[0]], $cacheKey->ttl());

        return $targets[0];
    }

    /** @param array<string, mixed> $record */
    private function belongsTo(string $target, array $record): bool
    {
        // A legacy record path can resolve to the new record ID. Compare the actual
        // registry response (or its version parent), never infer identity from a PID suffix.
        $resolved = $this->client->recordAt($target);
        if ($resolved !== null) {
            $parent = data_get($record, 'parent.id');

            return ($resolved['id'] ?? null) === $record['id']
                || (is_string($parent) && $parent !== '' && data_get($resolved, 'parent.id') === $parent);
        }

        // External instrument landing pages are compared, never requested.
        return $target === data_get($record, 'metadata.LandingPage');
    }
}
