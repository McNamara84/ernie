<?php

declare(strict_types=1);

namespace App\Services\DataCite;

use RuntimeException;

/** Resolves only the checksummed artifacts of a published DataCite revision. */
final class DataCiteSchemaPublicationService
{
    /** @return array{path: string, type: string, hash: string}|null */
    public function publicFile(string $name, ?string $revision = null): ?array
    {
        $revision ??= (string) config('datacite.schemas.revision');
        $directory = config('datacite.schemas.revisions.'.$revision);
        $types = [
            'schema.json' => 'application/schema+json',
            'context.jsonld' => 'application/ld+json',
            'manifest.json' => 'application/json',
            'README.md' => 'text/plain',
        ];

        if (! is_string($directory) || ! isset($types[$name])) {
            return null;
        }

        $root = realpath($directory);
        $path = realpath($directory.'/'.$name);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('A published DataCite artifact is unavailable.');
        }

        $hash = (string) hash_file('sha256', $path);
        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['revision'] ?? null) !== $revision) {
            throw new RuntimeException('The published DataCite manifest is invalid.');
        }

        if ($name !== 'manifest.json') {
            $expected = $manifest['files'][$name]['sha256'] ?? null;
            if (! is_string($expected) || ! hash_equals($expected, $hash)) {
                throw new RuntimeException('A published DataCite artifact has changed.');
            }
        }

        return ['path' => $path, 'type' => $types[$name], 'hash' => $hash];
    }
}
