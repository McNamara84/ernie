<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use Closure;
use RuntimeException;

/** Resolves the unchanged upstream Windows import using verified local files only. */
final class IgsnCoreSchemaResolver
{
    public const NAMESPACE = 'urn:FAIR-AIMS:Core:1';

    public const ENTRYPOINT = 'IGSN_Core_by_GFZ_DataServices.xsd';

    public function directory(): string
    {
        return (string) config('igsn_core.directory');
    }

    public function url(string $path = self::ENTRYPOINT): string
    {
        return url('/schemas/igsn/2.0/core/'.config('igsn_core.snapshot').'/'.$path);
    }

    /** @return list<array{path: string, git_blob: string, sha256: string, bytes: int, source: string}> */
    public function files(): array
    {
        $json = file_get_contents($this->directory().'/manifest.json');
        if ($json === false) {
            throw new RuntimeException('The IGSN Core manifest is unavailable.');
        }

        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['commit'] ?? null) !== config('igsn_core.snapshot') || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('The IGSN Core manifest is invalid.');
        }

        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_string($file['git_blob'] ?? null)
                || ! is_string($file['sha256'] ?? null) || ! is_int($file['bytes'] ?? null) || ! is_string($file['source'] ?? null)) {
                throw new RuntimeException('An IGSN Core manifest entry is invalid.');
            }
            $files[] = ['path' => $file['path'], 'git_blob' => $file['git_blob'], 'sha256' => $file['sha256'], 'bytes' => $file['bytes'], 'source' => $file['source']];
        }

        return $files;
    }

    /** @return array{path: string, hash: string, type: string}|null */
    public function publicFile(string $name): ?array
    {
        $files = array_column($this->files(), null, 'path');
        $original = $files[$name] ?? null;
        if ($original === null && ! in_array($name, ['manifest.json', 'catalog.xml', 'validation.xsd', 'README.md'], true)) {
            return null;
        }
        $root = realpath($this->directory());
        $path = realpath($this->directory().'/'.($original === null ? '' : 'upstream/').$name);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('An IGSN Core schema artifact is unavailable.');
        }
        $hash = (string) hash_file('sha256', $path);
        if ($original !== null && ! hash_equals($original['sha256'], $hash)) {
            throw new RuntimeException('An IGSN Core upstream artifact has changed.');
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
        $entrypoint = realpath($this->directory().'/validation.xsd');
        if ($entrypoint === false) {
            throw new RuntimeException('The IGSN Core validation entry point is missing.');
        }
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
        $allowed['X:/IGSN/DataCiteSchema4-7/kernel-4.7/metadata.xsd'] = $paths['DataCite4.7/metadata.xsd'];
        $allowed['file:///X:/IGSN/DataCiteSchema4-7/kernel-4.7/metadata.xsd'] = $paths['DataCite4.7/metadata.xsd'];

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
