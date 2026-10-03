<?php

declare(strict_types=1);

namespace App\Services\SchemaOrg;

/** @phpstan-import-type Content from CreativeWorkMetadataService */
final class DatasetMetadataService
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
            $metadata['distribution'] = $this->creativeWork->media($content['contentLinks'], 'DataDownload');
        }

        return $metadata;
    }
}
