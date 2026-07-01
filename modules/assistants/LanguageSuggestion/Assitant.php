<?php

namespace App\Assistants\ResourceLanguage;

use Nitotm\Eld\LanguageDetector;

class ResourceLanguageAssistant
{
    private LanguageDetector $detector;

    public function __construct()
    {
        $this->detector = new LanguageDetector();

        // Limit detection to languages that are currently relevant for the project.
        // This reduces false positives and keeps the first implementation simple.
        $this->detector->langSubset(['de', 'en', 'fr']);
    }

    public function suggest(array $resource): ?array
    {
        // Do not create a suggestion if the resource already has a language.
        // This prevents accidental overwrites in the preview step.
        if (!empty($resource['language'])) {
            return null;
        }

        // Collect all text fields that may provide language evidence.
        $texts = $this->collectTexts($resource);

        // If there is no usable text, no reliable suggestion can be created.
        if (count($texts) === 0) {
            return null;
        }

        $evidence = [];

        foreach ($texts as $textItem) {
            $detected = $this->detectLanguage($textItem['text']);

            // Skip text parts where the detector cannot return a useful result.
            if ($detected === null) {
                continue;
            }

            // Store each detection result as reviewer-facing evidence.
            // This is later used by the Assistance preview.
            $evidence[] = [
                'source' => $textItem['source'],
                'text' => $textItem['text'],
                'detected_language' => $detected['language'],
                'confidence' => $detected['confidence'],
                'reliable' => $detected['reliable'],
            ];
        }

        if (count($evidence) === 0) {
            return null;
        }

        // Decide whether the collected evidence is strong enough
        // to create a resource language suggestion.
        $decision = $this->decide($evidence);

        if ($decision === null) {
            return null;
        }

        // Task 1: Build the reviewer preview.
        // This payload contains exactly the data the Assistance card needs:
        // proposed language, confidence and evidence summary.
        return $this->buildReviewerPreview($resource, $decision, $evidence);
    }

    private function collectTexts(array $resource): array
    {
        $texts = [];

        foreach ($resource['titles'] ?? [] as $title) {
            $value = trim((string) ($title['title'] ?? ''));

            // Short titles are risky for language detection.
            if (mb_strlen($value) >= 10) {
                $texts[] = [
                    'source' => 'title',
                    'text' => $value,
                ];
            }
        }

        foreach ($resource['descriptions'] ?? [] as $description) {
            $value = trim((string) ($description['description'] ?? ''));

            // Descriptions provide stronger evidence, but should still contain enough text.
            if (mb_strlen($value) >= 30) {
                $texts[] = [
                    'source' => 'description',
                    'text' => $value,
                ];
            }
        }

        foreach ($resource['subjects'] ?? [] as $subject) {
            $value = trim((string) ($subject['subject'] ?? ''));

            if (mb_strlen($value) >= 10) {
                $texts[] = [
                    'source' => 'subject',
                    'text' => $value,
                ];
            }
        }

        return $texts;
    }

    private function detectLanguage(string $text): ?array
    {
        $result = $this->detector->detect($text);

        // "und" means undetermined.
        // In that case the assistant should not create evidence.
        if ($result->language === 'und') {
            return null;
        }

        $scores = $result->scores();
        $score = $scores[$result->language] ?? 0.0;

        return [
            'language' => $result->language,
            'confidence' => min(100, (int) round($score * 100)),
            'reliable' => $result->isReliable(),
        ];
    }

    private function decide(array $evidence): ?array
    {
        $languages = [];

        foreach ($evidence as $item) {
            // Only reliable detection results are used for the final suggestion.
            if (!$item['reliable']) {
                continue;
            }

            $language = $item['detected_language'];

            if (!isset($languages[$language])) {
                $languages[$language] = [
                    'count' => 0,
                    'confidence_sum' => 0,
                ];
            }

            $languages[$language]['count']++;
            $languages[$language]['confidence_sum'] += $item['confidence'];
        }

        if (count($languages) === 0) {
            return null;
        }

        // If multiple languages are detected, skip for now.
        // This avoids forcing multilingual records into one resource language.
        if (count($languages) > 1) {
            return null;
        }

        $bestLanguage = array_key_first($languages);
        $best = $languages[$bestLanguage];

        $confidence = (int) round($best['confidence_sum'] / $best['count']);

        // Low confidence suggestions should not be shown to the curator.
        if ($confidence < 60) {
            return null;
        }

        return [
            'language' => $bestLanguage,
            'confidence' => $confidence,
            'summary' => $this->buildSummary($bestLanguage, $evidence),
            'explanation' => sprintf(
                'The resource has no language value. The available metadata consistently indicates %s.',
                $this->languageLabel($bestLanguage)
            ),
        ];
    }

    private function buildReviewerPreview(array $resource, array $decision, array $evidence): array
    {
        // This method is the implementation of Task 1:
        // "Build the reviewer preview."
        //
        // The returned structure is designed for the Assistance card.
        // The UI can display:
        // - proposed_language / proposed_language_label
        // - confidence
        // - evidence_summary
        // - explanation
        // - evidence details if needed

        return [
            'type' => 'resource-language-suggestion',
            'resource_id' => $resource['id'] ?? null,

            // Main reviewer-facing recommendation.
            'proposed_language' => $decision['language'],
            'proposed_language_label' => $this->languageLabel($decision['language']),

            // Confidence shown in the Assistance card.
            'confidence' => $decision['confidence'],

            // Short explanation shown directly in the card preview.
            'evidence_summary' => $decision['summary'],

            // Longer explanation for reviewer trust and auditability.
            'explanation' => $decision['explanation'],

            // Detailed evidence can be used by the UI later,
            // for example in an expandable section.
            'evidence' => $evidence,

            // The suggestion is only prepared for review here.
            // Accepting and saving the language belongs to Task 2.
            'status' => 'pending',
        ];
    }

    private function buildSummary(string $language, array $evidence): string
    {
        $sources = array_unique(
            array_map(
                fn (array $item) => $item['source'],
                array_filter(
                    $evidence,
                    fn (array $item) => $item['detected_language'] === $language
                )
            )
        );

        return sprintf(
            '%s text indicate %s.',
            ucfirst(implode(' and ', $sources)),
            $this->languageLabel($language)
        );
    }

    private function languageLabel(string $language): string
    {
        return match ($language) {
            'de' => 'German',
            'en' => 'English',
            'fr' => 'French',
            default => strtoupper($language),
        };
    }
}