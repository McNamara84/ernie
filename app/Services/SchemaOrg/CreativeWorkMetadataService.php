<?php

declare(strict_types=1);

namespace App\Services\SchemaOrg;

/**
 * @phpstan-type Content array{mimeType: string|null, contentLinks: list<array{url: string, mimeType: string, contentSize: string|null}>, repositories: list<string>}
 */
final class CreativeWorkMetadataService
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  Content|null  $content
     * @return array<string, mixed>
     */
    public function map(array $metadata, ?array $content): array
    {
        if ($content !== null && $content['contentLinks'] !== []) {
            $metadata['associatedMedia'] = $this->media($content['contentLinks']);
        }

        return $metadata;
    }

    /**
     * @param  list<array{url: string, mimeType: string, contentSize: string|null}>  $links
     * @return list<array<string, string>>
     */
    public function media(array $links, string $type = 'MediaObject'): array
    {
        return array_map(static function (array $link) use ($type): array {
            $media = [
                '@type' => $type,
                'contentUrl' => $link['url'],
                'encodingFormat' => $link['mimeType'],
            ];
            if ($link['contentSize'] !== null) {
                $media['contentSize'] = $link['contentSize'];
            }

            return $media;
        }, $links);
    }
}
