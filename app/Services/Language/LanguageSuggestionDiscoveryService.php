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
            'the', 'and', 'of', 'in', 'for', 'with', 'on', 'from', 'by', 'an', 'to', 'as', 'data', 'dataset', 'study', 'research', 'using', 'analysis', 'model', 'samples', 'results', 'quality', 'groundwater', 'geological', 'science', 'project',
        ],
        'de' => [
            'und', 'der', 'die', 'das', 'mit', 'für', 'von', 'im', 'auf', 'ist', 'eine', 'in', 'zu', 'des', 'bei', 'daten', 'forschung', 'über', 'analyse', 'modell', 'studie', 'proben',
        ],
        'fr' => [
            'et', 'la', 'le', 'les', 'des', 'du', 'de', 'en', 'pour', 'avec', 'une', 'un', 'dans', 'que', 'est', 'étude', 'données', 'recherche', 'analyse', 'modèle', 'projet',
        ],
        'es' => [
            'y', 'la', 'el', 'los', 'las', 'de', 'del', 'por', 'para', 'con', 'en', 'un', 'una', 'estudio', 'datos', 'investigación', 'análisis', 'modelo',
        ],
        'it' => [
            'e', 'di', 'del', 'della', 'per', 'con', 'in', 'un', 'una', 'studio', 'dati', 'ricerca', 'analisi', 'modello',
        ],
        'nl' => [
            'de', 'en', 'het', 'een', 'met', 'voor', 'van', 'op', 'in', 'studie', 'data', 'onderzoek', 'analyse', 'model',
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

    private function candidateQuery(): Builder
    {
        return Resource::query()->whereNull('language_id');
    }

    private function inferLanguageSuggestion(Resource $resource): ?array
    {
        $languageCodes = $this->loadLanguageCodes();
        if ($languageCodes === []) {
            return null;
        }

        $explicitLanguages = $this->collectExplicitLanguages($resource);
        $explicitKnown = array_values(array_unique(array_filter($explicitLanguages, static fn (string $code): bool => in_array($code, $languageCodes, true))));

        if ($explicitKnown !== []) {
            $counts = array_count_values($explicitKnown);
            arsort($counts);
            $code = (string) array_key_first($counts);

            return [
                'code' => $code,
                'label' => $this->languageLabel($code),
                'confidence' => 0.95,
                'source' => 'explicit_language',
                'scores' => $counts,
                'evidence' => [
                    'explicit_languages' => $explicitKnown,
                    'text' => $this->collectText($resource),
                ],
            ];
        }

        $text = $this->collectText($resource);
        if (trim($text) === '') {
            return null;
        }

        $scores = [];
        foreach ($languageCodes as $code) {
            $score = $this->scoreText($text, $code);
            if ($score > 0) {
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

        if ($topScore < 3 || ($topScore - $secondScore) < 2) {
            return null;
        }

        return [
            'code' => $topCode,
            'label' => $this->languageLabel($topCode),
            'confidence' => round(min(0.9, 0.4 + ($topScore * 0.08)), 2),
            'source' => 'text_heuristic',
            'scores' => $scores,
            'evidence' => [
                'text' => $text,
                'top_score' => $topScore,
                'second_score' => $secondScore,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collectExplicitLanguages(Resource $resource): array
    {
        $languages = [];

        foreach ($resource->titles as $title) {
            if (is_string($title->language) && $title->language !== '') {
                $languages[] = $this->normaliseCode($title->language);
            }
        }

        foreach ($resource->descriptions as $description) {
            if (is_string($description->language) && $description->language !== '') {
                $languages[] = $this->normaliseCode($description->language);
            }
        }

        foreach ($resource->subjects as $subject) {
            if ($subject->language_id !== null) {
                $language = Language::query()->find($subject->language_id);
                if ($language instanceof Language) {
                    $languages[] = $this->normaliseCode($language->code);
                }
            }
        }

        return array_filter($languages, static fn (string $value): bool => $value !== '');
    }

    private function collectText(Resource $resource): string
    {
        $parts = [];

        foreach ($resource->titles as $title) {
            if (is_string($title->value) && $title->value !== '') {
                $parts[] = $title->value;
            }
        }

        foreach ($resource->descriptions as $description) {
            if (is_string($description->value) && $description->value !== '') {
                $parts[] = $description->value;
            }
        }

        foreach ($resource->subjects as $subject) {
            if (is_string($subject->value) && $subject->value !== '') {
                $parts[] = $subject->value;
            }
        }

        if ($resource->publisher !== null && is_string($resource->publisher->name) && $resource->publisher->name !== '') {
            $parts[] = $resource->publisher->name;
        }

        return trim(implode(' ', array_filter($parts, static fn (string $value): bool => $value !== '')));
    }

    private function scoreText(string $text, string $languageCode): int
    {
        $textLower = mb_strtolower($text);
        $score = 0;

        foreach (self::$stopwords[$languageCode] ?? [] as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/ui';
            $score += preg_match_all($pattern, $textLower);
        }

        foreach (self::$accentHints[$languageCode] ?? [] as $hint) {
            $score += substr_count($textLower, $hint) * 2;
        }

        return $score;
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

    private function normaliseCode(string $code): string
    {
        $value = str_replace('_', '-', strtolower(trim($code)));

        return explode('-', $value)[0] ?? $value;
    }
}
