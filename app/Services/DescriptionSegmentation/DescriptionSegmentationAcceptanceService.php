<?php

declare(strict_types=1);

namespace App\Services\DescriptionSegmentation;

use App\Models\AssistantSuggestion;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Support\DescriptionSegmentation\DescriptionSegmentationPolicy;
use Illuminate\Support\Facades\DB;

final readonly class DescriptionSegmentationAcceptanceService
{
    public function __construct(
        private DescriptionSegmentationPolicy $policy,
    ) {}

    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        $validation = $this->validatedPayload($suggestion);

        if ($validation['success'] === false) {
            return [
                'success' => false,
                'message' => $validation['message'],
            ];
        }

        return DB::transaction(function () use ($suggestion, $validation): array {
            /** @var Description|null $description */
            $description = Description::query()
                ->with('descriptionType')
                ->whereKey($suggestion->target_id)
                ->where('resource_id', $suggestion->resource_id)
                ->lockForUpdate()
                ->first();

            if (! $description instanceof Description) {
                return $this->failure('Description segmentation suggestion is stale because the source description no longer exists.');
            }

            if (! $description->isAbstract()) {
                return $this->failure('Description segmentation suggestion is stale because the source description is no longer an Abstract.');
            }

            /** @var array<string, mixed> $current */
            $current = $validation['current'];
            if ($this->hashText((string) $description->value) !== $current['value_hash']) {
                return $this->failure('Description segmentation suggestion is stale because the source Abstract text changed.');
            }

            /** @var string $remainingAbstract */
            $remainingAbstract = $validation['remaining_abstract'];
            /** @var list<array<string, mixed>> $segments */
            $segments = $validation['segments'];

            $typeIds = $this->descriptionTypeIds([
                DescriptionSegmentationPolicy::SOURCE_TYPE,
                ...array_values(array_unique(array_map(
                    static fn (array $segment): string => (string) $segment['description_type'],
                    $segments,
                ))),
            ]);

            if (! isset($typeIds[DescriptionSegmentationPolicy::SOURCE_TYPE])) {
                return $this->failure('Description segmentation suggestion cannot be applied because the Abstract description type is missing.');
            }

            foreach ($segments as $segment) {
                $targetType = (string) $segment['description_type'];
                if (! isset($typeIds[$targetType])) {
                    return $this->failure("Description segmentation suggestion cannot be applied because the {$targetType} description type is missing.");
                }
            }

            $description->forceFill([
                'value' => $remainingAbstract,
                'landing_page_html' => null,
            ]);

            if ($description->isDirty()) {
                $description->save();
            }

            $created = 0;

            foreach ($segments as $segment) {
                $targetType = (string) $segment['description_type'];
                $value = (string) $segment['value'];
                $language = $this->filledString($segment['language'] ?? null);

                $newDescription = Description::firstOrCreate([
                    'resource_id' => $suggestion->resource_id,
                    'description_type_id' => $typeIds[$targetType],
                    'value' => $value,
                    'language' => $language,
                ], [
                    'landing_page_html' => null,
                ]);

                if ($newDescription->wasRecentlyCreated) {
                    $created++;
                }
            }

            AssistantSuggestion::query()
                ->where('assistant_id', DescriptionSegmentationDiscoveryService::ASSISTANT_ID)
                ->where('target_type', DescriptionSegmentationDiscoveryService::TARGET_TYPE)
                ->where('target_id', $description->id)
                ->where('id', '!=', $suggestion->id)
                ->delete();

            return [
                'success' => true,
                'message' => "Description segmentation applied: Abstract updated and {$created} description segment(s) created.",
            ];
        });
    }

    /**
     * @return array{success: false, message: string}|array{success: true, current: array<string, mixed>, remaining_abstract: string, segments: list<array<string, mixed>>}
     */
    private function validatedPayload(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->assistant_id !== DescriptionSegmentationDiscoveryService::ASSISTANT_ID) {
            return $this->invalid('This description segmentation suggestion belongs to a different assistant.');
        }

        if ($suggestion->target_type !== DescriptionSegmentationDiscoveryService::TARGET_TYPE) {
            return $this->invalid('This description segmentation suggestion targets an unsupported entity type.');
        }

        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

        if ($this->filledString($metadata['contract_version'] ?? null) !== DescriptionSegmentationPreviewService::CONTRACT_VERSION) {
            return $this->invalid('Description segmentation suggestion is stale because the preview contract version changed.');
        }

        if ($this->filledString($metadata['policy_version'] ?? null) !== $this->policy->policyVersion()) {
            return $this->invalid('Description segmentation suggestion is stale because the segmentation policy version changed.');
        }

        $current = is_array($metadata['current'] ?? null) ? $metadata['current'] : [];
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];
        $segments = is_array($proposed['segments'] ?? null) ? $proposed['segments'] : [];
        $remainingAbstract = $this->filledString($proposed['remaining_abstract'] ?? null);

        if ((int) ($current['description_id'] ?? 0) !== $suggestion->target_id) {
            return $this->invalid('This description segmentation suggestion does not match its source description.');
        }

        if ((int) ($current['resource_id'] ?? 0) !== $suggestion->resource_id) {
            return $this->invalid('This description segmentation suggestion does not match its resource.');
        }

        if (($current['description_type'] ?? null) !== DescriptionSegmentationPolicy::SOURCE_TYPE) {
            return $this->invalid('Only Abstract source descriptions can be segmented.');
        }

        if ($this->filledString($current['value_hash'] ?? null) === null) {
            return $this->invalid('This description segmentation suggestion is missing its source text hash.');
        }

        if ($remainingAbstract === null || mb_strlen($remainingAbstract) < DescriptionSegmentationPolicy::MINIMUM_REMAINING_ABSTRACT_LENGTH) {
            return $this->invalid('This description segmentation suggestion would leave an Abstract that is too short.');
        }

        $normalizedSegments = $this->normalizedSegments($segments);

        if ($normalizedSegments === []) {
            return $this->invalid('This description segmentation suggestion does not contain any valid target segments.');
        }

        return [
            'success' => true,
            'current' => $current,
            'remaining_abstract' => $remainingAbstract,
            'segments' => $normalizedSegments,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $segments
     * @return list<array<string, mixed>>
     */
    private function normalizedSegments(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                return [];
            }

            $targetType = $this->filledString($segment['description_type'] ?? null);
            $value = $this->filledString($segment['value'] ?? null);
            $evidenceTypes = $this->stringList($segment['evidence_types'] ?? null);

            if ($targetType === null || $value === null) {
                return [];
            }

            if (mb_strlen($value) < DescriptionSegmentationPolicy::MINIMUM_SEGMENT_LENGTH) {
                return [];
            }

            if (! $this->policy->canSuggest(DescriptionSegmentationPolicy::SOURCE_TYPE, $targetType, $evidenceTypes)) {
                return [];
            }

            $normalized[] = [
                'description_type' => $this->policy->canonicalTypeSlug($targetType) ?? $targetType,
                'value' => $value,
                'language' => $this->filledString($segment['language'] ?? null),
                'evidence_types' => $evidenceTypes,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, int>
     */
    private function descriptionTypeIds(array $slugs): array
    {
        return DescriptionType::query()
            ->whereIn('slug', array_values(array_unique($slugs)))
            ->pluck('id', 'slug')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $string = $this->filledString($item);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function hashText(string $text): string
    {
        return hash('sha256', $text);
    }

    /** @return array{success: false, message: string} */
    private function invalid(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
