<?php

declare(strict_types=1);

namespace App\Services\SchemaOrg;

/** @phpstan-import-type Content from CreativeWorkMetadataService */
final class SoftwareMetadataService
{
    public function __construct(private readonly CreativeWorkMetadataService $creativeWork) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Content|null  $content
     * @return array<string, mixed>
     */
    public function map(array $metadata, ?array $content): array
    {
        if ($content === null) {
            return $metadata;
        }

        if ($content['repositories'] !== []) {
            $metadata['codeRepository'] = $this->singleOrList($content['repositories']);
        }
        if ($content['contentLinks'] !== []) {
            $metadata['downloadUrl'] = $this->singleOrList(array_column($content['contentLinks'], 'url'));
            $metadata['associatedMedia'] = $this->creativeWork->media($content['contentLinks'], 'DataDownload');
        }

        return $metadata;
    }

    /**
     * @param  list<string>  $values
     * @return string|list<string>
     */
    private function singleOrList(array $values): string|array
    {
        return count($values) === 1 ? $values[0] : $values;
    }
}
