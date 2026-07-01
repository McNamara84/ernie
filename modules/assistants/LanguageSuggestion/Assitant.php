<?php

namespace App\Assistants\ResourceLanguage;

use Nitotm\Eld\LanguageDetector;

class ResourceLanguageAssistant
{
    private LanguageDetector $detector;

    public function __construct()
    {
        $this->detector = new LanguageDetector();

        // Optional: only compare project-relevant languages.
        // For ERNIE this is useful if the baseline focuses on de/en/fr.
        $this->detector->langSubset(['de', 'en', 'fr']);
    }

    public function suggest(array $resource): ?array
    {
        if (!empty($resource['language'])) {
            return null;
        }

        $texts = $this->collectTexts($resource);

        if (count($texts) === 0) {
            return null;
        }

        $evidence = [];

        foreach ($texts as $textItem) {
            $detected = $this->detectLanguage($textItem['text']);

            if ($detected === null) {
                continue;
            }

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

        $decision = $this->decide($evidence);

        if ($decision === null) {
            return null;
        }

        return [
            'type' => 'resource-language-suggestion',
            'resource_id' => $resource['id'] ?? null,
            'proposed_language' => $decision['language'],
            'proposed_language_label' => $this->languageLabel($decision['language']),
            'confidence' => $decision['confidence'],
            'status' => 'suggested',
            'evidence_summary' => $decision['summary'],
            'explanation' => $decision['explanation'],
            'evidence' => $evidence,
        ];
    }

    private function collectTexts(array $resource): array
    {
        $texts = [];

        foreach ($resource['titles'] ?? [] as $title) {
            $value = trim((string) ($title['title'] ?? ''));

            if (mb_strlen($value) >= 10) {
                $texts[] = [
                    'source' => 'title',
                    'text' => $value,
                ];
            }
        }

        foreach ($resource['descriptions'] ?? [] as $description) {
            $value = trim((string) ($description['description'] ?? ''));

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

        arsort($languages);

        $bestLanguage = array_key_first($languages);
        $best = $languages[$bestLanguage];

        if (count($languages) > 1) {
            return null;
        }

        $confidence = (int) round($best['confidence_sum'] / $best['count']);

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