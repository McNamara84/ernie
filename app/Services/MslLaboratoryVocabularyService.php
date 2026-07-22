<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\ThesaurusSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads, validates and stores the versioned MSL laboratories vocabulary.
 *
 * @phpstan-type Laboratory array{
 *     identifier: string,
 *     name: string,
 *     display_name: string,
 *     affiliation_name: string,
 *     affiliation_ror: string|null,
 *     scientific_domain: string,
 *     country: string
 * }
 * @phpstan-type Source array{repository: string, ref: string, path: string, sha: string}
 * @phpstan-type Payload array{
 *     version: string,
 *     lastUpdated: string,
 *     total: int,
 *     source: Source,
 *     data: list<Laboratory>
 * }
 * @phpstan-type PublicPayload array{
 *     version: string,
 *     lastUpdated: string,
 *     total: int,
 *     data: list<Laboratory>
 * }
 */
class MslLaboratoryVocabularyService
{
    public function __construct(
        private readonly MslLaboratorySourceResolver $sourceResolver,
        private readonly VocabularyCacheService $cacheService
    ) {}

    /** @return Payload */
    public function fetchLatest(): array
    {
        $source = $this->sourceResolver->resolveLatest();

        try {
            $response = $this->pendingRequest()->get($source['download_url']);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Failed to download MSL laboratories vocabulary: '.$exception->getMessage(),
                0,
                $exception
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to download MSL laboratories vocabulary: HTTP {$response->status()}."
            );
        }

        $body = $response->body();
        $actualSha = sha1('blob '.strlen($body)."\0".$body);

        if (! hash_equals(strtolower($source['sha']), $actualSha)) {
            throw new \RuntimeException(
                'Downloaded MSL laboratories content does not match its Git blob SHA.'
            );
        }

        $laboratories = $this->decodeSource($body);

        return [
            'version' => $source['version'],
            'lastUpdated' => now()->utc()->toIso8601String(),
            'total' => count($laboratories),
            'source' => [
                'repository' => $source['repository'],
                'ref' => $source['ref'],
                'path' => $source['path'],
                'sha' => $source['sha'],
            ],
            'data' => $laboratories,
        ];
    }

    /** @return Payload */
    public function updateLocal(): array
    {
        $payload = $this->fetchLatest();
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $this->replaceAtomically($json);
        $this->cacheService->invalidateVocabularyCache(CacheKey::MSL_LABORATORIES);

        // Remove the untagged cache used by the previous remote-loading service.
        cache()->forget('msl_laboratories');

        return $payload;
    }

    /** @return Payload|null */
    public function getLocalPayload(): ?array
    {
        $path = $this->storagePath();

        if (! Storage::exists($path)) {
            return null;
        }

        $content = Storage::get($path);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('The local MSL laboratories vocabulary is unreadable.');
        }

        return $this->decodeLocalPayload($content);
    }

    /** @return PublicPayload|null */
    public function getPublicPayload(): ?array
    {
        $payload = $this->getLocalPayload();

        if ($payload === null) {
            return null;
        }

        return [
            'version' => $payload['version'],
            'lastUpdated' => $payload['lastUpdated'],
            'total' => $payload['total'],
            'data' => $payload['data'],
        ];
    }

    /** @return list<Laboratory> */
    private function decodeSource(string $content): array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'MSL laboratories download is not valid JSON: '.$exception->getMessage(),
                0,
                $exception
            );
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \RuntimeException('MSL laboratories download must contain a JSON array.');
        }

        if ($decoded === []) {
            throw new \RuntimeException('MSL laboratories download must contain at least one laboratory.');
        }

        return $this->normalizeLaboratories($decoded);
    }

    /**
     * @param  list<mixed>  $laboratories
     * @return list<Laboratory>
     */
    private function normalizeLaboratories(array $laboratories): array
    {
        $normalized = [];
        $identifiers = [];
        $requiredStrings = [
            'identifier',
            'name',
            'display_name',
            'affiliation_name',
            'scientific_domain',
            'country',
        ];

        foreach ($laboratories as $index => $laboratory) {
            if (! is_array($laboratory)) {
                throw new \RuntimeException("MSL laboratory at index {$index} must be an object.");
            }

            foreach ([...$requiredStrings, 'affiliation_ror'] as $key) {
                if (! array_key_exists($key, $laboratory)) {
                    throw new \RuntimeException(
                        "MSL laboratory at index {$index} is missing required field '{$key}'."
                    );
                }
            }

            $values = [];

            foreach ($requiredStrings as $key) {
                if (! is_string($laboratory[$key]) || trim($laboratory[$key]) === '') {
                    throw new \RuntimeException(
                        "MSL laboratory at index {$index} has an invalid '{$key}' value."
                    );
                }

                $values[$key] = trim($laboratory[$key]);
            }

            $identifier = $values['identifier'];

            if (isset($identifiers[$identifier])) {
                throw new \RuntimeException(
                    "MSL laboratory identifier '{$identifier}' occurs more than once."
                );
            }

            $identifiers[$identifier] = true;

            $normalized[] = [
                'identifier' => $identifier,
                'name' => $values['name'],
                'display_name' => $values['display_name'],
                'affiliation_name' => $values['affiliation_name'],
                'affiliation_ror' => $this->normalizeRor($laboratory['affiliation_ror'], $identifier),
                'scientific_domain' => $values['scientific_domain'],
                'country' => $values['country'],
            ];
        }

        return $normalized;
    }

    private function normalizeRor(mixed $value, string $identifier): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $ror = is_string($value) ? trim($value) : '';

        if (preg_match('~^https://ror\.org/[0-9a-z]{9}$~iD', $ror) === 1) {
            return $ror;
        }

        Log::warning('Invalid MSL laboratory ROR normalized to null.', [
            'identifier' => $identifier,
        ]);

        return null;
    }

    /** @return Payload */
    private function decodeLocalPayload(string $content): array
    {
        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'The local MSL laboratories vocabulary contains invalid JSON.',
                0,
                $exception
            );
        }

        if (! is_array($payload)) {
            throw new \RuntimeException(
                'The local MSL laboratories vocabulary has an invalid root structure.'
            );
        }

        foreach (['version', 'lastUpdated', 'total', 'source', 'data'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new \RuntimeException(
                    "The local MSL laboratories vocabulary is missing '{$key}'."
                );
            }
        }

        if (! is_string($payload['version'])
            || preg_match('/^\d+(?:\.\d+)+$/D', trim($payload['version'])) !== 1
            || ! is_string($payload['lastUpdated'])
            || ! $this->isRfc3339DateTime(trim($payload['lastUpdated']))
            || ! is_int($payload['total'])
            || ! is_array($payload['source'])
            || ! is_array($payload['data'])
            || ! array_is_list($payload['data'])) {
            throw new \RuntimeException(
                'The local MSL laboratories vocabulary metadata is invalid.'
            );
        }

        if ($payload['data'] === []) {
            throw new \RuntimeException(
                'The local MSL laboratories vocabulary must contain at least one laboratory.'
            );
        }

        foreach (['repository', 'ref', 'path', 'sha'] as $key) {
            if (! is_string($payload['source'][$key] ?? null)
                || trim($payload['source'][$key]) === '') {
                throw new \RuntimeException(
                    "The local MSL laboratories vocabulary has invalid source metadata '{$key}'."
                );
            }
        }

        if (preg_match('/^[0-9a-f]{40}$/iD', trim($payload['source']['sha'])) !== 1) {
            throw new \RuntimeException(
                "The local MSL laboratories vocabulary has invalid source metadata 'sha'."
            );
        }

        $data = $this->normalizeLaboratories($payload['data']);

        if ($payload['total'] !== count($data)) {
            throw new \RuntimeException(
                'The local MSL laboratories vocabulary total does not match its data.'
            );
        }

        return [
            'version' => trim($payload['version']),
            'lastUpdated' => trim($payload['lastUpdated']),
            'total' => $payload['total'],
            'source' => [
                'repository' => trim($payload['source']['repository']),
                'ref' => trim($payload['source']['ref']),
                'path' => trim($payload['source']['path']),
                'sha' => strtolower(trim($payload['source']['sha'])),
            ],
            'data' => $data,
        ];
    }

    private function replaceAtomically(string $json): void
    {
        $path = $this->storagePath();
        $directory = dirname($path);
        $temporaryPath = ($directory === '.' ? '' : $directory.'/')
            .'.'.basename($path).'.'.Str::uuid().'.tmp';

        try {
            if (! Storage::put($temporaryPath, $json)) {
                throw new \RuntimeException(
                    'Failed to write the temporary MSL laboratories vocabulary.'
                );
            }

            $written = Storage::get($temporaryPath);

            if (! is_string($written)) {
                throw new \RuntimeException(
                    'Failed to read the temporary MSL laboratories vocabulary.'
                );
            }

            // Verify the complete persisted representation before replacement.
            $this->decodeLocalPayload($written);

            if (! @rename(Storage::path($temporaryPath), Storage::path($path))) {
                throw new \RuntimeException(
                    'Failed to atomically replace the local MSL laboratories vocabulary.'
                );
            }
        } finally {
            if (Storage::exists($temporaryPath)) {
                Storage::delete($temporaryPath);
            }
        }
    }

    private function isRfc3339DateTime(string $value): bool
    {
        $normalized = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:sP') === $normalized;
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

    private function storagePath(): string
    {
        return (new ThesaurusSetting([
            'type' => ThesaurusSetting::TYPE_MSL_LABORATORIES,
        ]))->getFilePath();
    }
}
