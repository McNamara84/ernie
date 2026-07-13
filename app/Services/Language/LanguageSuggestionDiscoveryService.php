<?php

declare(strict_types=1);

namespace App\Services\Language;

use App\Models\Language;
use App\Models\Resource;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class LanguageSuggestionDiscoveryService
{
    private const CHUNK_SIZE = 50;

    /**
     * @var array<string, array<int, string>>
     */
    private static array $stopwords = [
        'en' => [
            'the', 'and', 'of', 'with', 'this', 'that', 'from', 'into', 'are', 'were', 'was', 'have', 'has', 'data', 'dataset', 'study', 'research', 'analysis', 'model', 'quality', 'groundwater', 'geological', 'science', 'project',
        ],
        'de' => [
            'und', 'der', 'die', 'das', 'mit', 'für', 'von', 'im', 'auf', 'ist', 'eine', 'zu', 'des', 'bei', 'daten', 'forschung', 'über', 'analyse', 'modell', 'studie', 'proben',
        ],
        'fr' => [
            'et', 'la', 'le', 'les', 'des', 'du', 'de', 'pour', 'avec', 'une', 'un', 'dans', 'que', 'est', 'étude', 'données', 'recherche', 'analyse', 'modèle', 'projet',
        ],
        'es' => [
            'y', 'la', 'el', 'los', 'las', 'de', 'del', 'por', 'para', 'con', 'en', 'un', 'una', 'estudio', 'datos', 'investigación', 'análisis', 'modelo',
        ],
        'it' => [
            'e', 'di', 'del', 'della', 'per', 'con', 'un', 'una', 'studio', 'dati', 'ricerca', 'analisi', 'modello',
        ],
        'nl' => [
            'de', 'en', 'het', 'een', 'met', 'voor', 'van', 'op', 'studie', 'data', 'onderzoek', 'analyse', 'model',
        ],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $accentHints = [
        'de' => ['ä', 'ö', 'ü', 'ß'],
        'fr' => ['é', 'è', 'ê', 'ë', 'à', 'â', 'î', 'ï', 'ô', 'ù', 'û', 'ç'],
        'es' => ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
        'it' => ['à', 'è', 'é', 'ì', 'í', 'î', 'ò', 'ó', 'ù', 'ú'],
        'nl' => ['é', 'è', 'ë', 'ï', 'ö', 'ü'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $signalWords = [
        'en' => ['data', 'dataset', 'study', 'research', 'analysis', 'model', 'quality', 'groundwater', 'geological', 'formation', 'aquifer', 'subsurface', 'systems', 'techniques', 'scientific'],
        'de' => ['grundwasser', 'geologische', 'studie', 'analyse', 'daten', 'forschung', 'proben', 'techniken', 'methode', 'untersuchung', 'systemen', 'qualitäts', 'qualität'],
        'fr' => ['étude', 'qualité', 'données', 'recherche', 'analyse', 'géologique', 'eaux', 'souterraines', 'techniques', 'avancées', 'modèle'],
        'es' => ['análisis', 'calidad', 'agua', 'subterránea', 'investigación', 'acuíferos', 'técnicas', 'datos', 'estudio', 'modelo'],
        'it' => ['studio', 'qualità', 'acqua', 'sotterranea', 'ricerca', 'falde', 'acquifere', 'tecniche', 'dati', 'modello'],
        'nl' => ['studie', 'kwaliteit', 'grondwater', 'onderzoek', 'aquiferen', 'technieken', 'gegevens', 'analyse', 'model'],
    ];

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $count = 0;
        $processed = 0;
        $query = $this->candidateQuery();
        $total = (clone $query)->count();

        $query
            ->with(['titles', 'descriptions', 'subjects', 'publisher'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($resources) use (&$count, &$processed, $total, $storeSuggestion, $onProgress): void {
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    $processed++;
                    $onProgress("Checking resource {$processed} of {$total}");

                    $suggestion = $this->inferLanguageSuggestion($resource);
                    if ($suggestion === null) {
                        continue;
                    }

                    $stored = $storeSuggestion(
                        $resource->id,
                        'resource_language',
                        $resource->id,
                        $suggestion['code'],
                        $suggestion['label'],
                        $suggestion['confidence'],
                        [
                            'source' => $suggestion['source'],
                            'scores' => $suggestion['scores'],
                            'evidence' => $suggestion['evidence'],
                        ],
                    );

                    if ($stored) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @return Builder<Resource>
     */
    private function candidateQuery(): Builder
    {
        return Resource::query()->whereNull('language_id');
    }

    /**
     * @return array{code: string, label: string, confidence: float, source: string, scores: array<string, int>, evidence: array<string, mixed>}|null
     */
    private function inferLanguageSuggestion(Resource $resource): ?array
    {
        $languageCodes = $this->loadLanguageCodes();
        if ($languageCodes === []) {
            return null;
        }

        $evidence = $this->collectEvidence($resource, $languageCodes);

        $explicitEvidence = array_values(array_filter($evidence, static fn (array $entry): bool => in_array($entry['source'], ['title', 'description', 'subject'], true)));
        $explicitModes = array_values(array_unique(array_filter(array_column($explicitEvidence, 'language'), static fn (?string $code): bool => $code !== null && in_array($code, $languageCodes, true))));
        $explicitLanguages = array_values(array_filter($explicitModes, static fn (string $code): bool => in_array($code, $languageCodes, true)));

        if ($explicitLanguages !== []) {
            $counts = array_count_values($explicitLanguages);
            arsort($counts);
            $code = (string) array_key_first($counts);
            $sourceCount = count($counts);

            if ($sourceCount > 1) {
                return null;
            }

            return [
                'code' => $code,
                'label' => $this->languageLabel($code),
                'confidence' => 0.95,
                'source' => 'explicit_language',
                'scores' => $counts,
                'evidence' => [
                    'explicit_languages' => $explicitLanguages,
                    'evidence' => $evidence,
                    'text' => $this->collectText($resource),
                ],
            ];
        }

        $text = $this->collectText($resource);
        if ($text === '' || ! $this->hasMeaningfulSignal($text)) {
            return null;
        }

        if ($this->looksLikeNameOnly($text)) {
            return null;
        }

        $scores = [];
        foreach ($languageCodes as $code) {
            $score = $this->scoreText($text, $code);
            if ($score > 1) {
                $scores[$code] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $topCode = (string) array_key_first($scores);
        $topScore = (int) ($scores[$topCode] ?? 0);
        $secondScore = 0;

        if (count($scores) > 1) {
            $values = array_values($scores);
            $secondScore = (int) ($values[1] ?? 0);
        }

        if ($topScore < 2 || ($topScore - $secondScore) < 1) {
            return null;
        }

        if ($topCode === 'en' && $this->containsCrossLanguageStopwords($text, $topCode)) {
            return null;
        }

        return [
            'code' => $topCode,
            'label' => $this->languageLabel($topCode),
            'confidence' => round(min(0.9, 0.6 + min(0.3, max(0, ($topScore - 3) * 0.05))), 2),
            'source' => 'text_heuristic',
            'scores' => $scores,
            'evidence' => [
                'text' => $text,
                'top_score' => $topScore,
                'second_score' => $secondScore,
                'evidence' => $evidence,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $languageCodes
     * @return array<int, array{source: string, language: string|null}>
     */
    private function collectEvidence(Resource $resource, array $languageCodes): array
    {
        $evidence = [];

        foreach ($resource->titles as $title) {
            if ($title->language !== null && $title->language !== '') {
                $code = $this->normaliseCode($title->language);
                if (in_array($code, $languageCodes, true)) {
                    $evidence[] = ['source' => 'title', 'language' => $code];
                }
            }
        }

        foreach ($resource->descriptions as $description) {
            if ($description->language !== null && $description->language !== '') {
                $code = $this->normaliseCode($description->language);
                if (in_array($code, $languageCodes, true)) {
                    $evidence[] = ['source' => 'description', 'language' => $code];
                }
            }
        }

        foreach ($resource->subjects as $subject) {
            if ($subject->language_id !== null) {
                $language = Language::query()->find($subject->language_id);
                if ($language instanceof Language) {
                    $code = $this->normaliseCode($language->code);
                    if (in_array($code, $languageCodes, true)) {
                        $evidence[] = ['source' => 'subject', 'language' => $code];
                    }
                }
            }
        }

        if ($resource->publisher !== null && $resource->publisher->name !== '') {
            $publisherLanguage = $this->inferPublisherLanguage($resource->publisher->name);
            if ($publisherLanguage !== null && in_array($publisherLanguage, $languageCodes, true)) {
                $evidence[] = ['source' => 'publisher', 'language' => $publisherLanguage];
            }
        }

        return $evidence;
    }

    private function collectText(Resource $resource): string
    {
        $parts = [];

        foreach ($resource->titles as $title) {
            if ($title->value !== '') {
                $parts[] = $title->value;
            }
        }

        foreach ($resource->descriptions as $description) {
            if ($description->value !== '') {
                $parts[] = $description->value;
            }
        }

        foreach ($resource->subjects as $subject) {
            if ($subject->value !== '') {
                $parts[] = $subject->value;
            }
        }

        return trim(implode(' ', $parts));
    }

    private function scoreText(string $text, string $languageCode): int
    {
        $textLower = mb_strtolower($text);
        $score = 0;

        foreach (self::$stopwords[$languageCode] ?? [] as $word) {
            $pattern = '/\b'.preg_quote($word, '/').'\b/ui';
            $score += preg_match_all($pattern, $textLower);
        }

        foreach (self::$signalWords[$languageCode] ?? [] as $word) {
            $pattern = '/\b'.preg_quote($word, '/').'\b/ui';
            $score += preg_match_all($pattern, $textLower) * 2;
        }

        if ($languageCode === 'de') {
            $score += substr_count($textLower, 'ä') * 2;
            $score += substr_count($textLower, 'ö') * 2;
            $score += substr_count($textLower, 'ü') * 2;
            $score += substr_count($textLower, 'ß') * 2;
        }

        foreach (self::$accentHints[$languageCode] ?? [] as $hint) {
            $score += substr_count($textLower, $hint) * 2;
        }

        return $score;
    }

    private function hasMeaningfulSignal(string $text): bool
    {
        if (preg_match('/[+×÷=→←≈∞≤≥∼∝]/u', $text) === 1) {
            return false;
        }

        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($text));
        if (! is_string($normalized) || trim($normalized) === '') {
            return false;
        }

        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        $meaningfulTokens = array_values(array_filter($tokens, static function (string $token): bool {
            if (mb_strlen($token) < 3) {
                return false;
            }

            if (preg_match('/^[A-Z0-9]{2,}$/u', $token) === 1 || preg_match('/\d/u', $token) === 1) {
                return false;
            }

            return preg_match('/\p{L}/u', $token) === 1;
        }));

        if (count($meaningfulTokens) < 2) {
            return false;
        }

        return true;
    }

    private function looksLikeNameOnly(string $text): bool
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($text));
        if (! is_string($normalized)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        $alphaTokens = array_values(array_filter($tokens, static fn (string $token): bool => preg_match('/\p{L}/u', $token) === 1));
        if (count($alphaTokens) !== count($tokens)) {
            return false;
        }

        $uppercaseTokens = array_filter($alphaTokens, static fn (string $token): bool => preg_match('/^[A-ZÄÖÜÉÈÊËÀÂÎÏÔÙÛÇ]+$/u', $token) === 1);

        return count($uppercaseTokens) === count($alphaTokens);
    }

    private function containsCrossLanguageStopwords(string $text, string $topCode): bool
    {
        if ($topCode !== 'en') {
            return false;
        }

        $textLower = mb_strtolower($text);
        $englishStopwords = self::$stopwords['en'] ?? [];
        $englishSignalCount = 0;
        foreach ($englishStopwords as $word) {
            $pattern = '/\b'.preg_quote($word, '/').'\b/ui';
            if (preg_match($pattern, $textLower) === 1) {
                $englishSignalCount++;
            }
        }

        if ($englishSignalCount < 2) {
            return false;
        }

        foreach (['de', 'fr', 'es', 'it', 'nl'] as $languageCode) {
            $foreignSignalCount = 0;
            foreach (self::$stopwords[$languageCode] ?? [] as $word) {
                if (in_array($word, $englishStopwords, true)) {
                    continue;
                }

                $pattern = '/\b'.preg_quote($word, '/').'\b/ui';
                if (preg_match($pattern, $textLower) === 1) {
                    $foreignSignalCount++;
                }
            }

            if ($foreignSignalCount >= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function loadLanguageCodes(): array
    {
        return Language::query()
            ->where('active', true)
            ->pluck('code')
            ->filter()
            ->map(static fn (mixed $code): string => strtolower((string) $code))
            ->values()
            ->all();
    }

    private function languageLabel(string $code): string
    {
        $language = Language::query()->where('code', $code)->first();

        if ($language === null) {
            return strtoupper($code);
        }

        return sprintf('%s (%s)', $language->name, $language->code);
    }

    private function inferPublisherLanguage(string $publisherName): ?string
    {
        $name = mb_strtolower($publisherName);

        if (str_contains($name, 'gfz') || str_contains($name, 'helmholtz')) {
            return 'en';
        }

        if (str_contains($name, 'deutsche')) {
            return 'de';
        }

        if (str_contains($name, 'france') || str_contains($name, 'français')) {
            return 'fr';
        }

        return null;
    }

    private function normaliseCode(string $code): string
    {
        $value = str_replace('_', '-', strtolower(trim($code)));

        return explode('-', $value)[0];
    }
}
