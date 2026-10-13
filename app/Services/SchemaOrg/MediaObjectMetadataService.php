<?php

declare(strict_types=1);

namespace App\Services\SchemaOrg;

/** @phpstan-import-type Content from CreativeWorkMetadataService */
final class MediaObjectMetadataService
{
    public function __construct(private readonly CreativeWorkMetadataService $creativeWork) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Content|null  $content
     * @return array<string, mixed>
     */
    public function map(array $metadata, ?array $content): array
    {
        if ($content !== null && $content['contentLinks'] !== []) {
            // Preserve each representation's MIME type and size; an archive is not an image/video.
            $metadata['encoding'] = $this->creativeWork->media($content['contentLinks']);
        }

        return $metadata;
    }
}
