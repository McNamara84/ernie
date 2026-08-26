<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Support\IgsnIdentifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class IgsnSampleImageStorageService
{
    public function __construct(
        private readonly IgsnSampleImageUrlService $urlService,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @return array{status: string, message: string}
     */
    public function sync(IgsnMetadata $metadata, bool $force = false): array
    {
        $classification = $this->urlService->classifySourceUrl($metadata->sample_image_source_url);

        if ($classification['status'] === IgsnSampleImageUrlService::STATUS_MISSING) {
            return ['status' => 'missing', 'message' => (string) $classification['reason']];
        }

        if ($classification['status'] === IgsnSampleImageUrlService::STATUS_UNSUPPORTED) {
            return ['status' => 'unsupported', 'message' => (string) $classification['reason']];
        }

        if ($classification['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL) {
            return $this->persistExternal($metadata, (string) $classification['external_url']);
        }

        return $this->storeManaged($metadata, (string) $classification['source_url'], $force);
    }

    /**
     * @return array{status: string, message: string}
     */
    private function persistExternal(IgsnMetadata $metadata, string $externalUrl): array
    {
        $oldPath = $metadata->sample_image_storage_path;
        $changed = $metadata->sample_image_external_url !== $externalUrl
            || $oldPath !== null
            || $metadata->sample_image_mime_type !== null
            || $metadata->sample_image_size !== null;

        if (! $changed) {
            return ['status' => 'external', 'message' => 'External image URL is already current.'];
        }

        $metadata->forceFill([
            'sample_image_external_url' => $externalUrl,
            'sample_image_storage_path' => null,
            'sample_image_mime_type' => null,
            'sample_image_size' => null,
        ])->save();

        $this->deleteAfterCommit($oldPath);
        $this->forgetLandingPage($metadata);

        return ['status' => 'external', 'message' => 'External image URL stored.'];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function storeManaged(IgsnMetadata $metadata, string $sourceUrl, bool $force): array
    {
        $disk = Storage::disk($this->disk());
        if (! $force
            && is_string($metadata->sample_image_storage_path)
            && $metadata->sample_image_storage_path !== ''
            && hash_equals(
                hash('sha256', $sourceUrl),
                pathinfo($metadata->sample_image_storage_path, PATHINFO_FILENAME),
            )
            && $disk->exists($metadata->sample_image_storage_path)) {
            return ['status' => 'unchanged', 'message' => 'Managed image is already stored.'];
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'ernie-igsn-image-');
        if ($temporaryPath === false) {
            return ['status' => 'failed', 'message' => 'Unable to create a temporary image file.'];
        }

        try {
            $maxBytes = max(1, (int) config('igsn_images.max_bytes', 20 * 1024 * 1024));
            $response = Http::connectTimeout(max(1, (int) config('igsn_images.connect_timeout_seconds', 5)))
                ->timeout(max(1, (int) config('igsn_images.timeout_seconds', 30)))
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $temporaryPath,
                    'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('The sample image exceeds the configured size limit.');
                        }
                    },
                ])
                ->get($sourceUrl);

            if ($response->redirect()) {
                throw new RuntimeException('Sample image redirects are not allowed.');
            }
            if (! $response->successful()) {
                throw new RuntimeException('The sample image request returned HTTP '.$response->status().'.');
            }

            // Laravel HTTP fakes do not process Guzzle's sink option.
            if (filesize($temporaryPath) === 0 && $response->body() !== '') {
                file_put_contents($temporaryPath, $response->body());
            }

            $size = filesize($temporaryPath);
            if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                throw new RuntimeException('The sample image has an invalid file size.');
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            $allowedMimeTypes = (array) config('igsn_images.allowed_mime_types', ['image/jpeg' => 'jpg']);
            if (! is_string($mimeType) || ! isset($allowedMimeTypes[$mimeType]) || ! is_string($allowedMimeTypes[$mimeType])) {
                throw new RuntimeException('The sample image has an unsupported MIME type.');
            }

            $metadata->loadMissing('resource');
            $resource = $metadata->getRelation('resource');
            $handle = $resource instanceof Resource && is_string($resource->doi)
                ? IgsnIdentifier::handleFromDoi($resource->doi)
                : null;
            if ($handle === null) {
                throw new RuntimeException('The sample image belongs to an invalid IGSN DOI.');
            }

            $targetPath = sprintf(
                'igsn-sample-images/%s/%s.%s',
                strtolower($handle),
                hash('sha256', $sourceUrl),
                $allowedMimeTypes[$mimeType],
            );
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to read the downloaded sample image.');
            }

            try {
                if (! $disk->put($targetPath, $stream)) {
                    throw new RuntimeException('Unable to store the sample image.');
                }
            } finally {
                fclose($stream);
            }

            $oldPath = $metadata->sample_image_storage_path;
            try {
                $metadata->forceFill([
                    'sample_image_external_url' => null,
                    'sample_image_storage_path' => $targetPath,
                    'sample_image_mime_type' => $mimeType,
                    'sample_image_size' => $size,
                ])->save();
            } catch (Throwable $exception) {
                $disk->delete($targetPath);
                throw $exception;
            }

            if ($oldPath !== $targetPath) {
                $this->deleteAfterCommit($oldPath);
                $this->deleteAfterRollback($targetPath);
            }
            $this->forgetLandingPage($metadata);

            return ['status' => 'stored', 'message' => 'Managed image stored.'];
        } catch (ConnectionException $exception) {
            return $this->failed($metadata, 'transport_error', $exception);
        } catch (Throwable $exception) {
            return $this->failed($metadata, $exception->getMessage(), $exception);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /** @return array{status: string, message: string} */
    private function failed(IgsnMetadata $metadata, string $message, Throwable $exception): array
    {
        Log::warning('IGSN sample image synchronization failed', [
            'resource_id' => $metadata->resource_id,
            'doi' => $this->resourceDoiForLogging($metadata),
            'source_classification' => 'managed',
            'error_class' => $message,
            'exception_type' => $exception::class,
        ]);

        return ['status' => 'failed', 'message' => $message];
    }

    private function forgetLandingPage(IgsnMetadata $metadata): void
    {
        $metadata->loadMissing('resource.landingPage');
        $resource = $metadata->getRelation('resource');
        if (! $resource instanceof Resource) {
            return;
        }

        $landingPage = $resource->landingPage;
        if ($landingPage !== null && $landingPage->isPublished()) {
            $this->landingPageCache->forgetById((int) $landingPage->id);
        }
    }

    private function deleteAfterCommit(mixed $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $disk = $this->disk();
        DB::afterCommit(static function () use ($disk, $path): void {
            Storage::disk($disk)->delete($path);
        });
    }

    private function deleteAfterRollback(string $path): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            return;
        }

        $disk = $this->disk();
        $connection->afterRollBack(static function () use ($disk, $path): void {
            Storage::disk($disk)->delete($path);
        });
    }

    private function resourceDoiForLogging(IgsnMetadata $metadata): ?string
    {
        $resource = $metadata->relationLoaded('resource')
            ? $metadata->getRelation('resource')
            : null;

        if ($resource instanceof Resource) {
            return is_string($resource->doi) ? $resource->doi : null;
        }

        try {
            $doi = $metadata->resource()->value('doi');

            return is_string($doi) ? $doi : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function disk(): string
    {
        return (string) config('igsn_images.disk', 'public');
    }
}
