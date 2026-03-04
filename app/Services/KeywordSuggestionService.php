<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Subject;
use Illuminate\Support\Facades\Cache;

/**
 * Service for providing keyword suggestions from published resources.
 *
 * Returns deduplicated keywords (Free Keywords + Thesaurus Keywords)
 * that are actually used by published resources, suitable for
 * autocomplete/filter UI in the public portal.
 */
class KeywordSuggestionService
{
    /**
     * Get distinct keyword suggestions from published resources.
     *
     * Results are cached for performance. Each suggestion includes
     * the keyword value, its scheme (if any), and usage count.
     *
     * @return array<int, array{value: string, scheme: string|null, count: int}>
     */
    public function getSuggestions(): array
    {
        /** @var array<int, array{value: string, scheme: string|null, count: int}> */
        return Cache::remember(
            CacheKey::PORTAL_KEYWORD_SUGGESTIONS->key(),
            CacheKey::PORTAL_KEYWORD_SUGGESTIONS->ttl(),
            fn (): array => $this->fetchSuggestions(),
        );
    }

    /**
     * Invalidate the cached keyword suggestions.
     */
    public function invalidateCache(): void
    {
        Cache::forget(CacheKey::PORTAL_KEYWORD_SUGGESTIONS->key());
    }

    /**
     * Fetch distinct keywords from the database.
     *
     * Only includes keywords from resources that have a published landing page.
     * Groups by value and subject_scheme, counting occurrences.
     *
     * @return array<int, array{value: string, scheme: string|null, count: int}>
     */
    private function fetchSuggestions(): array
    {
        return Subject::query()
            ->select('value', 'subject_scheme')
            ->selectRaw('COUNT(DISTINCT resource_id) as usage_count')
            ->whereHas('resource', function ($query): void {
                $query->whereHas('landingPage', function ($q): void {
                    $q->where('is_published', true);
                });
            })
            ->groupBy('value', 'subject_scheme')
            ->orderBy('value')
            ->get()
            ->map(static fn (Subject $subject): array => [
                'value' => $subject->value,
                'scheme' => $subject->subject_scheme,
                'count' => (int) $subject->getAttribute('usage_count'),
            ])
            ->all();
    }
}
