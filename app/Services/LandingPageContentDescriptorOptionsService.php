<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;

final class LandingPageContentDescriptorOptionsService
{
    public function __construct(private readonly DigitalContentSizeService $sizeService) {}

    /**
     * @return array{
     *     available_formats: list<array{id: int, value: string}>,
     *     available_sizes: list<array{id: int, label: string, content_size: string}>
     * }
     */
    public function for(Resource $resource): array
    {
        $resource->loadMissing(['resourceType', 'formats', 'sizes']);

        $formats = $resource->formats
            ->sortBy('id')
            ->map(function ($format): ?array {
                $mimeType = SizeFormatFormatNormalizerService::normalize($format->value);

                if (preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mimeType) !== 1) {
                    return null;
                }

                return ['id' => $format->id, 'value' => $mimeType];
            })
            ->filter()
            ->values()
            ->all();

        $sizes = $resource->sizes
            ->sortBy('id')
            ->map(function ($size) use ($resource): ?array {
                $bytes = $this->sizeService->forResource($size, $resource);

                return $bytes === null ? null : [
                    'id' => $size->id,
                    'label' => $size->export_string,
                    'content_size' => $bytes,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'available_formats' => $formats,
            'available_sizes' => $sizes,
        ];
    }
}
