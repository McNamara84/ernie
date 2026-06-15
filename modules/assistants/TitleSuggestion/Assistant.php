<?php

declare(strict_types=1);

namespace Modules\Assistants\TitleSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Title;
use App\Services\Assistance\GenericTableAssistant;
use Closure;
use Nitotm\Eld\LanguageDetector;

class Assistant extends GenericTableAssistant
{
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'fr'];

    private LanguageDetector $detector;

    public function __construct()
    {
        parent::__construct();

        $this->detector = new LanguageDetector();
    }

    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    /**
     * Discover titles without a language value and create title-language suggestions.
     *
     * @param Closure(string): void $onProgress
     */
    protected function discover(Closure $onProgress): int
    {
        $titles = Title::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('language')
                    ->orWhere('language', '');
            })
            ->whereNotNull('value')
            ->where('value', '<>', '')
            ->cursor();

        $count = 0;

        foreach ($titles as $title) {
            $onProgress("Detecting language for title #{$title->id}");

            $detection = $this->detectLanguage((string) $title->value);

            if ($detection === null) {
                continue;
            }

            $currentLanguage = $this->currentLanguage($title);

            $stored = $this->storeSuggestion(
                resourceId: $title->resource_id,
                targetType: 'title',
                targetId: $title->id,
                suggestedValue: $detection['code'],
                suggestedLabel: $this->suggestionLabel((string) $title->value, $detection),
                similarityScore: $detection['confidence'],
                metadata: [
                    'title_text' => (string) $title->value,
                    'current_language' => $currentLanguage,
                    'proposed_language' => $detection['code'],
                    'proposed_language_label' => $detection['label'],
                    'confidence' => $detection['confidence'],
                    'reason' => $detection['reason'],
                    'source_hash' => $this->sourceHash($title),
                    'source_snapshot' => [
                        'title_id' => $title->id,
                        'title_text' => (string) $title->value,
                        'current_language' => $currentLanguage,
                        'resource_id' => $title->resource_id,
                    ],
                ],
            );

            if ($stored) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Apply an accepted title-language suggestion.
     *
     * @return array{success: bool, message: string}
     */
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        $title = Title::find($suggestion->target_id);

        if ($title === null) {
            return [
                'success' => false,
                'message' => 'Title record not found.',
            ];
        }

        $metadata = $this->metadata($suggestion);

        if ($this->isStale($title, $metadata)) {
            return [
                'success' => false,
                'message' => 'Suggestion is stale because the title data changed after discovery. Please run discovery again.',
            ];
        }

        $currentLanguage = $this->currentLanguage($title);
        $proposedLanguage = strtolower((string) $suggestion->suggested_value);

        if (! in_array($proposedLanguage, self::SUPPORTED_LANGUAGES, true)) {
            return [
                'success' => false,
                'message' => "Unsupported language '{$proposedLanguage}'. Only de, en and fr are supported.",
            ];
        }

        if ($currentLanguage !== null && $currentLanguage !== $proposedLanguage) {
            return [
                'success' => false,
                'message' => "Title already has language '{$currentLanguage}'. It was not overwritten automatically.",
            ];
        }

        if ($currentLanguage === $proposedLanguage) {
            return [
                'success' => true,
                'message' => "Title language is already set to {$proposedLanguage}.",
            ];
        }

        $title->language = $proposedLanguage;
        $title->save();

        $languageLabel = $metadata['proposed_language_label']
            ?? $suggestion->suggested_label
            ?? strtoupper($proposedLanguage);

        return [
            'success' => true,
            'message' => "Title language set to {$languageLabel}.",
        ];
    }

    /**
     * Detect the language of a title text.
     *
     * Only German, English and French suggestions are supported.
     *
     * @return array{code: string, label: string, confidence: float, reason: string}|null
     */
    private function detectLanguage(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $result = $this->detector->detect($text);

        if ($result === null || empty($result->language)) {
            return null;
        }

        $languageCode = strtolower((string) $result->language);

        if (! in_array($languageCode, self::SUPPORTED_LANGUAGES, true)) {
            return null;
        }

        $scores = method_exists($result, 'scores') ? $result->scores() : [];
        $confidence = isset($scores[$languageCode]) ? (float) $scores[$languageCode] : 0.0;

        if (method_exists($result, 'isReliable') && ! $result->isReliable()) {
            return null;
        }

        return [
            'code' => $languageCode,
            'label' => $this->languageLabel($languageCode),
            'confidence' => max(0.0, min(1.0, $confidence)),
            'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
        ];
    }

    /**
     * Build a human-readable label for the generic Assistance card.
     *
     * @param array{code: string, label: string, confidence: float, reason: string} $detection
     */
    private function suggestionLabel(string $titleText, array $detection): string
    {
        return sprintf(
            '%s (%s) for "%s"',
            $detection['label'],
            $detection['code'],
            $this->shortTitle($titleText),
        );
    }

    private function shortTitle(string $title): string
    {
        $title = trim($title);

        if (mb_strlen($title) <= 90) {
            return $title;
        }

        return mb_substr($title, 0, 87) . '...';
    }

    private function currentLanguage(Title $title): ?string
    {
        $language = $title->language;

        if ($language === null || $language === '') {
            return null;
        }

        return strtolower((string) $language);
    }

    private function sourceHash(Title $title): string
    {
        return hash('sha256', implode('|', [
            (string) $title->id,
            trim((string) $title->value),
            (string) ($title->language ?? ''),
            (string) $title->resource_id,
        ]));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function isStale(Title $title, array $metadata): bool
    {
        $storedHash = $metadata['source_hash'] ?? null;

        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        return ! hash_equals($storedHash, $this->sourceHash($title));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(AssistantSuggestion $suggestion): array
    {
        $metadata = $suggestion->metadata ?? [];

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function languageLabel(string $code): string
    {
        return match ($code) {
            'de' => 'German',
            'en' => 'English',
            'fr' => 'French',
            default => strtoupper($code),
        };
    }
}