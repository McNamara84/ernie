<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IgsnMetadata;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class IgsnMetadataObserver
{
    private const PUBLIC_IMAGE_FIELDS = [
        'sample_image_external_url',
        'sample_image_storage_path',
        'sample_image_mime_type',
        'sample_image_size',
    ];

    public function __construct(private readonly LandingPageRenderDataCacheService $landingPageCache) {}

    public function updated(IgsnMetadata $metadata): void
    {
        if (! $metadata->wasChanged(self::PUBLIC_IMAGE_FIELDS)) {
            return;
        }

        if ($metadata->wasChanged('sample_image_storage_path')) {
            $oldPath = $metadata->getOriginal('sample_image_storage_path');
            if ($oldPath !== $metadata->sample_image_storage_path) {
                $this->deleteAfterCommit($oldPath);
            }
        }

        $metadata->loadMissing('resource.landingPage');
        $landingPage = $metadata->resource->landingPage;
        if ($landingPage !== null && $landingPage->isPublished()) {
            $this->landingPageCache->forgetById((int) $landingPage->id);
        }
    }

    public function deleted(IgsnMetadata $metadata): void
    {
        $this->deleteAfterCommit($metadata->sample_image_storage_path);
    }

    private function deleteAfterCommit(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            $disk = (string) config('igsn_images.disk', 'public');

            DB::afterCommit(static function () use ($disk, $path): void {
                Storage::disk($disk)->delete($path);
            });
        }
    }
}
