<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Discovers the newest stable MSL laboratories release on GitHub. */
class MslLaboratorySourceResolverService
{
    /**
     * @return array{repository: string, ref: string, version: string, path: string, sha: string, download_url: string}
     */
    public function resolveLatest(): array
    {
        $repository = $this->configString('repository');
        $ref = $this->configString('ref');
        $basePath = trim($this->configString('laboratories_base_path'), '/');
        $filename = $this->configString('laboratories_filename');

        $directoryResponse = $this->request(
            $this->contentsUrl($repository, $basePath),
            'laboratories version discovery',
            $ref
        );
        $directoryEntries = $directoryResponse->json();

        if (! is_array($directoryEntries) || ! array_is_list($directoryEntries)) {
            throw new \RuntimeException('MSL laboratories version discovery returned an unexpected response.');
        }

        $versions = [];

        foreach ($directoryEntries as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'dir') {
                continue;
            }

            $name = $entry['name'] ?? null;

            if (is_string($name) && preg_match('/^\d+(?:\.\d+)+$/D', $name) === 1) {
                $versions[] = $name;
            }
        }

        if ($versions === []) {
            throw new \RuntimeException('No stable MSL laboratories version directory was found.');
        }

        usort($versions, static fn (string $left, string $right): int => version_compare($right, $left));

        return $this->resolveFile($repository, $ref, $basePath, $filename, $versions[0]);
    }

    /**
     * @return array{repository: string, ref: string, version: string, path: string, sha: string, download_url: string}
     */
    private function resolveFile(
        string $repository,
        string $ref,
        string $basePath,
        string $filename,
        string $version
    ): array {
        $path = "{$basePath}/{$version}/{$filename}";
        $response = $this->request(
            $this->contentsUrl($repository, $path),
            "MSL laboratories file metadata for version {$version}",
            $ref
        );
        $metadata = $response->json();

        if (! is_array($metadata)
            || ($metadata['type'] ?? null) !== 'file'
            || ! is_string($metadata['sha'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/iD', trim($metadata['sha'])) !== 1
            || ! is_string($metadata['download_url'] ?? null)
            || trim($metadata['download_url']) === '') {
            throw new \RuntimeException("MSL laboratories file metadata for version {$version} is incomplete.");
        }

        return [
            'repository' => $repository,
            'ref' => $ref,
            'version' => $version,
            'path' => $path,
            'sha' => trim($metadata['sha']),
            'download_url' => trim($metadata['download_url']),
        ];
    }

    private function contentsUrl(string $repository, string $path): string
    {
        $apiBase = rtrim($this->configString('github_api_base'), '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        return "{$apiBase}/repos/{$repository}/contents/{$encodedPath}";
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => 'ERNIE-MSL-Vocabulary-Updater'])
            ->timeout(max(1, (int) config('msl.http_timeout', 30)))
            ->retry(
                max(1, (int) config('msl.http_retries', 3)),
                max(0, (int) config('msl.http_retry_delay_ms', 200)),
                throw: false
            );
    }

    private function request(string $url, string $operation, string $ref): Response
    {
        try {
            $response = $this->pendingRequest()->get($url, ['ref' => $ref]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException("Failed during {$operation}: {$exception->getMessage()}", 0, $exception);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Failed during {$operation}: HTTP {$response->status()}.");
        }

        return $response;
    }

    private function configString(string $key): string
    {
        $value = trim((string) config("msl.{$key}"));

        if ($value === '') {
            throw new \RuntimeException("Missing MSL configuration value: {$key}.");
        }

        return $value;
    }
}
