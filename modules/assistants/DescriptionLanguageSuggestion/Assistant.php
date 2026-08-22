<?php

declare(strict_types=1);

namespace Modules\Assistants\DescriptionLanguageSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Description;
use App\Services\Assistance\GenericTableAssistant;
use App\Support\DescriptionLanguage;
use App\Support\LanguageTag;
use Closure;
use Illuminate\Support\Facades\DB;
use Nitotm\Eld\LanguageDetector;

final class Assistant extends GenericTableAssistant
{
    private const TARGET_TYPE = 'description';

    private LanguageDetector $detector;

    public function __construct()
    {
        parent::__construct();

        $this->detector = new LanguageDetector;
        $this->detector->langSubset(DescriptionLanguage::EDITOR_CODES);
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /** @param  Closure(string): void  $onProgress */
    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        $descriptions = Description::query()
            ->with('descriptionType:id,name,slug')
            ->where(function ($query): void {
                $query->whereNull('language')->orWhere('language', '');
            })
            ->whereNotNull('value')
            ->where('value', '<>', '')
            ->cursor();

        $count = 0;

        foreach ($descriptions as $description) {
            $onProgress("Detecting language for description #{$description->id}");
            $detection = $this->detectLanguage((string) $description->value);

            if ($detection === null) {
                continue;
            }

            $type = $description->descriptionType?->name
                ?? $description->descriptionType?->slug
                ?? 'Description';

            $stored = $this->storeSuggestion(
                resourceId: $description->resource_id,
                targetType: self::TARGET_TYPE,
                targetId: $description->id,
                suggestedValue: $detection['code'],
                suggestedLabel: sprintf(
                    '%s: %s (%s) · %d%% confidence · “%s”',
                    $type,
                    $detection['label'],
                    $detection['code'],
                    $this->confidencePercent($detection['confidence']),
                    $this->shortText((string) $description->value),
                ),
                similarityScore: $detection['confidence'],
                metadata: [
                    'description_type' => $type,
                    'text_preview' => $this->shortText((string) $description->value),
                    'current_language' => null,
                    'proposed_language' => $detection['code'],
                    'proposed_language_label' => $detection['label'],
                    'confidence' => $detection['confidence'],
                    'confidence_percent' => $this->confidencePercent($detection['confidence']),
                    'reason' => 'Detected from description text using reliable ELD detection limited to German and English.',
                    'source_hash' => $this->sourceHash($description),
                    'source_snapshot' => [
                        'description_id' => $description->id,
                        'resource_id' => $description->resource_id,
                        'description_type_id' => $description->description_type_id,
                        'current_language' => null,
                    ],
                ],
            );

            if ($stored) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array{success: bool, message: string} */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        $payload = $this->validatedPayload($suggestion);

        if ($payload === null) {
            return ['success' => false, 'message' => 'This description language suggestion is invalid.'];
        }

        return DB::transaction(function () use ($payload, $suggestion): array {
            $description = Description::query()
                ->whereKey($payload['description_id'])
                ->where('resource_id', $payload['resource_id'])
                ->lockForUpdate()
                ->first();

            if ($description === null) {
                return ['success' => false, 'message' => 'The description no longer exists or belongs to another resource.'];
            }

            $metadata = $this->metadata($suggestion);
            $storedHash = $metadata['source_hash'] ?? null;

            if (! is_string($storedHash) || ! hash_equals($storedHash, $this->sourceHash($description))) {
                return ['success' => false, 'message' => 'Suggestion is stale because the description changed. Please run discovery again.'];
            }

            $currentLanguage = LanguageTag::normalize($description->language);

            if ($currentLanguage !== null && $currentLanguage !== $payload['language']) {
                return ['success' => false, 'message' => "Description already has language '{$currentLanguage}' and was not overwritten."];
            }

            if ($currentLanguage === $payload['language']) {
                return ['success' => true, 'message' => "Description language is already set to {$payload['language']}."];
            }

            $description->language = $payload['language'];
            $description->save();

            return [
                'success' => true,
                'message' => 'Description language set to '.DescriptionLanguage::label($payload['language']).'.',
            ];
        });
    }

    /** @return array{description_id: int, resource_id: int, language: string}|null */
    private function validatedPayload(AssistantSuggestion $suggestion): ?array
    {
        if ($suggestion->target_type !== self::TARGET_TYPE) {
            return null;
        }

        $descriptionId = $this->positiveInt($suggestion->target_id);
        $resourceId = $this->positiveInt($suggestion->resource_id);
        $language = LanguageTag::normalize($suggestion->suggested_value);

        if ($descriptionId === null || $resourceId === null || ! DescriptionLanguage::isEditorLanguage($language)) {
            return null;
        }

        $metadata = $this->metadata($suggestion);
        $snapshot = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        $snapshotDescriptionId = $this->positiveInt($snapshot['description_id'] ?? null);
        $snapshotResourceId = $this->positiveInt($snapshot['resource_id'] ?? null);

        if (($snapshotDescriptionId !== null && $snapshotDescriptionId !== $descriptionId)
            || ($snapshotResourceId !== null && $snapshotResourceId !== $resourceId)) {
            return null;
        }

        return [
            'description_id' => $descriptionId,
            'resource_id' => $resourceId,
            'language' => $language,
        ];
    }

    /** @return array{code: string, label: string, confidence: float}|null */
    private function detectLanguage(string $text): ?array
    {
        $text = trim(strip_tags($text));

        if ($text === '') {
            return null;
        }

        $result = $this->detector->detect($text);
        $code = LanguageTag::normalize($result->language ?? null);

        if ($code === null || ! DescriptionLanguage::isEditorLanguage($code) || ! $result->isReliable()) {
            return null;
        }

        $scores = $result->scores();
        $confidence = isset($scores[$code]) ? (float) $scores[$code] : 0.0;

        return [
            'code' => $code,
            'label' => DescriptionLanguage::label($code),
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    private function confidencePercent(float $confidence): int
    {
        return (int) round(max(0.0, min(1.0, $confidence)) * 100);
    }

    private function shortText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim(strip_tags($text))) ?? '';

        return mb_strlen($text) <= 120 ? $text : mb_substr($text, 0, 117).'...';
    }

    private function sourceHash(Description $description): string
    {
        return hash('sha256', implode('|', [
            (string) $description->id,
            trim((string) $description->value),
            (string) $description->description_type_id,
            (string) ($description->language ?? ''),
            (string) $description->resource_id,
        ]));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function metadata(AssistantSuggestion $suggestion): array
    {
        if (is_array($suggestion->metadata)) {
            return $suggestion->metadata;
        }

        if (is_string($suggestion->metadata)) {
            $decoded = json_decode($suggestion->metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
