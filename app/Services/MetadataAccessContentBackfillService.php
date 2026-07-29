<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Format;
use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Models\Size;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;
use Illuminate\Database\Eloquent\Model;

final class MetadataAccessContentBackfillService
{
    public function __construct(private readonly DigitalContentSizeService $sizeService) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     access_changes: int,
     *     format_changes: int,
     *     size_changes: int,
     *     sample_access_counts: array<string, int>,
     *     review: list<array{resource_id: int, category: string, value: string, detail: string}>
     * }
     */
    public function run(bool $apply = false): array
    {
        $result = [
            'dry_run' => ! $apply,
            'access_changes' => 0,
            'format_changes' => 0,
            'size_changes' => 0,
            'sample_access_counts' => [],
            'review' => [],
        ];

        Resource::query()
            ->with([
                'resourceType',
                'igsnMetadata',
                'dates.dateType',
                'formats',
                'sizes',
                'landingPage.files',
                'landingPage.links',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($resources) use ($apply, &$result): void {
                foreach ($resources as $resource) {
                    $this->backfillAccess($resource, $apply, $result);
                    $this->backfillContent($resource, $apply, $result);
                }
            });

        ksort($result['sample_access_counts']);

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function backfillAccess(Resource $resource, bool $apply, array &$result): void
    {
        $candidate = null;

        if ($resource->isIgsn()) {
            $rawValue = trim((string) $resource->igsnMetadata?->sample_access);
            $key = $rawValue === '' ? '(empty)' : $rawValue;
            $result['sample_access_counts'][$key] = ($result['sample_access_counts'][$key] ?? 0) + 1;
            $candidate = AccessLevel::fromSampleAccess($rawValue === '' ? null : $rawValue);

            if ($resource->access_level === null && $candidate === null) {
                $this->review($result, $resource, 'access_unknown_igsn', $rawValue, 'Unknown or empty IGSN sample_access value.');
            }
        } elseif ($resource->access_level === null) {
            $candidate = $resource->landingPage?->downloads_unavailable
                ? AccessLevel::METADATA_ONLY
                : AccessLevel::OPEN;
        }

        if ($resource->access_level === null && $candidate !== null) {
            $result['access_changes']++;
            if ($apply) {
                $resource->access_level = $candidate;
                $resource->save();
            }
        }

        $effectiveLevel = $resource->access_level ?? $candidate;
        if ($effectiveLevel === AccessLevel::EMBARGOED && ! $this->hasAvailableDate($resource)) {
            $this->review($result, $resource, 'embargo_missing_available_date', $resource->igsnMetadata?->sample_access ?? '', 'Embargoed access requires an Available date.');
        }
    }

    /** @param array<string, mixed> $result */
    private function backfillContent(Resource $resource, bool $apply, array &$result): void
    {
        $landingPage = $resource->landingPage;
        if (! $landingPage instanceof LandingPage) {
            return;
        }

        $targets = $this->contentTargets($landingPage);
        if ($targets === []) {
            return;
        }

        $formatTargets = array_values(array_filter(
            $targets,
            static fn (array $target): bool => $target['format_id'] === null,
        ));
        $formats = $resource->formats
            ->filter(static fn (Format $format): bool => self::validMimeType($format) !== null)
            ->values();

        if ($formatTargets !== [] && $formats->count() === 1) {
            foreach ($formatTargets as $target) {
                $result['format_changes']++;
                if ($apply) {
                    $this->assignTarget($target['model'], 'format_id', $formats->first()->id);
                }
            }
        } elseif ($formatTargets !== []) {
            $this->review(
                $result,
                $resource,
                $formats->isEmpty() ? 'format_missing' : 'format_ambiguous',
                (string) $formats->count(),
                'Unassigned content URLs require exactly one valid resource MIME type for automatic backfill.',
            );
        }

        if ($resource->isIgsn()) {
            return;
        }

        $sizeTargets = array_values(array_filter(
            $targets,
            static fn (array $target): bool => $target['size_id'] === null,
        ));
        if ($sizeTargets === []) {
            return;
        }

        $sizes = $resource->sizes
            ->filter(fn (Size $size): bool => $this->sizeService->isEligible($size, $resource))
            ->values();

        if (count($targets) === 1 && count($sizeTargets) === 1 && $sizes->count() === 1) {
            $result['size_changes']++;
            if ($apply) {
                $this->assignTarget($sizeTargets[0]['model'], 'size_id', $sizes->first()->id);
            }

            return;
        }

        $category = $sizes->isEmpty() ? 'digital_size_missing' : 'digital_size_ambiguous';
        $this->review(
            $result,
            $resource,
            $category,
            $sizes->count().' sizes / '.count($targets).' URLs',
            'Automatic size backfill requires exactly one digital size and one content URL.',
        );
    }

    /**
     * @return list<array{model: LandingPage|LandingPageFile|LandingPageLink, format_id: int|null, size_id: int|null}>
     */
    private function contentTargets(LandingPage $landingPage): array
    {
        $targets = [];

        if ($landingPage->files->isNotEmpty()) {
            foreach ($landingPage->files->sortBy([['position', 'asc'], ['id', 'asc']]) as $file) {
                $targets[] = ['model' => $file, 'format_id' => $file->format_id, 'size_id' => $file->size_id];
            }
        } elseif (trim((string) $landingPage->ftp_url) !== '') {
            $targets[] = [
                'model' => $landingPage,
                'format_id' => $landingPage->ftp_format_id,
                'size_id' => $landingPage->ftp_size_id,
            ];
        }

        foreach ($landingPage->links
            ->where('kind', LandingPageLink::KIND_DOWNLOAD)
            ->sortBy([['position', 'asc'], ['id', 'asc']]) as $link) {
            $targets[] = ['model' => $link, 'format_id' => $link->format_id, 'size_id' => $link->size_id];
        }

        return $targets;
    }

    private static function validMimeType(Format $format): ?string
    {
        $mimeType = SizeFormatFormatNormalizerService::normalize($format->value);

        return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/i', $mimeType) === 1
            ? $mimeType
            : null;
    }

    private function assignTarget(Model $target, string $column, int $id): void
    {
        $target->setAttribute(
            $target instanceof LandingPage ? 'ftp_'.$column : $column,
            $id,
        );
        $target->save();
    }

    private function hasAvailableDate(Resource $resource): bool
    {
        return $resource->dates->contains(
            static fn (ResourceDate $date): bool => strcasecmp($date->dateType?->slug ?? '', 'available') === 0
                && trim((string) ($date->start_date ?? $date->date_value)) !== '',
        );
    }

    /** @param array<string, mixed> $result */
    private function review(array &$result, Resource $resource, string $category, string $value, string $detail): void
    {
        $result['review'][] = [
            'resource_id' => $resource->id,
            'category' => $category,
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
