<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use Closure;
use RuntimeException;

/** Resolves portable schema revisions using verified local files only. */
final class IgsnCoreSchemaResolver
{
    public const NAMESPACE = 'urn:FAIR-AIMS:Core:1';

    public const ENTRYPOINT = 'core.xsd';

    public function directory(?string $revision = null): string
    {
        $directory = config('igsn_core.revisions.'.($revision ?? config('igsn_core.revision')));
        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException('The IGSN Core schema revision is unavailable.');
        }

        return $directory;
    }

    public function url(string $path = self::ENTRYPOINT, ?string $revision = null): string
    {
        return url('/schemas/igsn/2.0/'.($revision === null ? '' : $revision.'/').$path);
    }

    /** @return list<array{path: string, sha256: string, bytes: int}> */
    public function files(?string $revision = null): array
    {
        $json = file_get_contents($this->directory($revision).'/manifest.json');
        if ($json === false) {
            throw new RuntimeException('The IGSN Core manifest is unavailable.');
        }

        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['revision'] ?? null) !== ($revision ?? config('igsn_core.revision')) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('The IGSN Core manifest is invalid.');
        }

        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)
                || ! is_string($file['sha256'] ?? null) || ! is_int($file['bytes'] ?? null)) {
                throw new RuntimeException('An IGSN Core manifest entry is invalid.');
            }
            $files[] = ['path' => $file['path'], 'sha256' => $file['sha256'], 'bytes' => $file['bytes']];
        }

        return $files;
    }

    /** @return array{path: string, hash: string, type: string}|null */
    public function publicFile(string $name, ?string $revision = null): ?array
    {
        $files = array_column($this->files($revision), null, 'path');
        $artifact = $files[$name] ?? null;
        if ($artifact === null && ! in_array($name, ['manifest.json', 'README.md'], true)) {
            return null;
        }
        $root = realpath($this->directory($revision));
        $path = realpath($this->directory($revision).'/'.$name);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('An IGSN Core schema artifact is unavailable.');
        }
        $hash = (string) hash_file('sha256', $path);
        if ($artifact !== null && ! hash_equals($artifact['sha256'], $hash)) {
            throw new RuntimeException('An IGSN Core schema artifact has changed.');
        }

        return ['path' => $path, 'hash' => $hash, 'type' => match (pathinfo($name, PATHINFO_EXTENSION)) {
            'json' => 'application/json',
            'md' => 'text/plain',
            default => 'application/xml',
        }];
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $validate
     * @return T
     */
    public function withLocalSchemas(Closure $validate): mixed
    {
        $paths = [];
        foreach ($this->files() as $file) {
            if (! str_ends_with($file['path'], '.xsd')) {
                continue;
            }
            $path = $this->publicFile($file['path'])['path'] ?? throw new RuntimeException('An IGSN Core schema is missing.');
            $paths[$file['path']] = $path;
        }
        $entrypoint = $paths[self::ENTRYPOINT] ?? throw new RuntimeException('The IGSN Core validation entry point is missing.');
        $allowed = [];
        foreach ($paths as $relative => $path) {
            $allowed[$relative] = $path;
            if (str_starts_with($relative, 'DataCite4.7/include/')) {
                $allowed[substr($relative, strlen('DataCite4.7/'))] = $path;
            }
            $allowed[str_replace('\\', '/', $path)] = $path;
            $allowed['file://'.str_replace('\\', '/', $path)] = $path;
            $allowed[str_replace('\\', '/', $this->directory().'/'.$relative)] = $path;
        }
        $allowed[str_replace('\\', '/', $entrypoint)] = $entrypoint;

        $previous = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static function (?string $public, ?string $system) use ($allowed) {
            $key = str_replace('\\', '/', (string) $system);
            $path = $allowed[$key] ?? null;

            return $path === null ? null : fopen($path, 'rb');
        });
        try {
            return $validate($entrypoint);
        } finally {
            libxml_set_external_entity_loader($previous);
        }
    }
}
