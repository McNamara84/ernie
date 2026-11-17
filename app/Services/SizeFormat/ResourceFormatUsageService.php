<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Global format popularity in the Resources workspace, counting each resource once. */
final readonly class ResourceFormatUsageService
{
    public const CACHE_KEY = 'editor:resource-format-usage:v1';

    public function __construct(private MimeTypeRegistry $registry) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinute(), function (): array {
            $counts = [];
            $previousResourceId = null;
            $seen = [];
            $formats = Format::query()
                ->select(['resource_id', 'value'])
                ->whereHas('resource', static fn (Builder $resource) => $resource
                    ->whereDoesntHave('resourceType', static fn (Builder $type) => $type->where('slug', 'physical-object')))
                ->orderBy('resource_id')
                ->cursor();

            foreach ($formats as $format) {
                if ($format->resource_id !== $previousResourceId) {
                    $previousResourceId = $format->resource_id;
                    $seen = [];
                }
                $mimeType = SizeFormatFormatNormalizerService::normalize($format->value);
                if ($mimeType === '' || isset($seen[$mimeType]) || ! $this->registry->contains($mimeType)) {
                    continue;
                }
                $seen[$mimeType] = true;
                $counts[$mimeType] = ($counts[$mimeType] ?? 0) + 1;
            }

            return $counts;
        });
    }
}
