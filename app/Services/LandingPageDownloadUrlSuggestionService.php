<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Setting;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LandingPageDownloadUrlSuggestionService
{
    public const SETTING_KEY = 'landing_page_download_url_suggestion_order';

    /** @return list<string> */
    public function order(): array
    {
        $decoded = json_decode((string) Setting::getValue(self::SETTING_KEY, '[]'), true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value)))
            : [];
    }

    /**
     * @return array{domains: list<array{value: string, usage_count: int}>, urls: list<array{value: string, usage_count: int}>}
     */
    public function suggestions(bool $limit = true): array
    {
        $sources = Cache::rememberForever(
            CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key(),
            static fn (): array => self::buildDownloadUrlSuggestionPayload(self::loadDownloadUrlSuggestionSourceCounts()),
        );
        $domains = [];
        foreach ($this->order() as $value) {
            $normalized = self::normalizePrefix($value);
            if ($normalized === null || isset($domains[$normalized])) {
                continue;
            }
            $count = 0;
            foreach ($sources['urls'] as $source) {
                if (self::matchesPrefix($source['value'], $normalized)) {
                    $count += $source['usage_count'];
                }
            }
            $domains[$normalized] = ['value' => $normalized, 'usage_count' => $count];
        }
        foreach ($sources['domains'] as $domain) {
            $domains[$domain['value']] ??= $domain;
        }

        return [
            'domains' => $limit ? array_slice(array_values($domains), 0, 20) : array_values($domains),
            'urls' => $limit ? array_slice($sources['urls'], 0, 20) : $sources['urls'],
        ];
    }

    public static function normalizePrefix(string $value): ?string
    {
        return self::normalizeDownloadSuggestionUrl(trim($value));
    }

    private static function matchesPrefix(string $url, string $prefix): bool
    {
        if ($url === $prefix) {
            return true;
        }
        if (! str_starts_with($url, $prefix)) {
            return false;
        }

        return str_ends_with($prefix, '/') || in_array(substr($url, strlen($prefix), 1), ['/', '?'], true);
    }

    public static function forgetAfterCommit(): void
    {
        DB::afterCommit(static fn () => CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget());
    }

    /**
     * @param  array<string, int>  $sourceUrlCounts
     * @return array{
     *     domains: list<array{value: string, usage_count: int}>,
     *     urls: list<array{value: string, usage_count: int}>
     * }
     */
    private static function buildDownloadUrlSuggestionPayload(array $sourceUrlCounts): array
    {
        /** @var array<string, int> $domainCounts */
        $domainCounts = [];
        /** @var array<string, int> $urlCounts */
        $urlCounts = [];

        foreach ($sourceUrlCounts as $sourceUrl => $sourceUsageCount) {
            $normalizedUrl = self::normalizeDownloadSuggestionUrl($sourceUrl);

            if ($normalizedUrl === null) {
                continue;
            }

            $urlCounts[$normalizedUrl] = ($urlCounts[$normalizedUrl] ?? 0) + $sourceUsageCount;

            $domain = self::extractDownloadSuggestionDomain($normalizedUrl);

            if ($domain !== null) {
                $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + $sourceUsageCount;
            }
        }

        return [
            'domains' => self::sortDownloadSuggestionCounts($domainCounts),
            'urls' => self::sortDownloadSuggestionCounts($urlCounts),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function loadDownloadUrlSuggestionSourceCounts(): array
    {
        /** @var list<object{value: string, usage_count: int|string}> $groupedSources */
        $groupedSources = [
            ...DB::table('landing_pages')
                ->selectRaw('ftp_url as value, COUNT(*) as usage_count')
                ->whereNotNull('ftp_url')
                ->whereRaw("TRIM(ftp_url) <> ''")
                ->groupBy('ftp_url')
                ->get()
                ->all(),
            ...DB::table('landing_page_files')
                ->selectRaw('url as value, COUNT(*) as usage_count')
                ->whereRaw("TRIM(url) <> ''")
                ->groupBy('url')
                ->get()
                ->all(),
        ];

        $sourceCounts = [];

        foreach ($groupedSources as $groupedSource) {
            $sourceCounts[$groupedSource->value] = ($sourceCounts[$groupedSource->value] ?? 0) + (int) $groupedSource->usage_count;
        }

        return $sourceCounts;
    }

    private static function normalizeDownloadSuggestionUrl(string $url): ?string
    {
        $normalizedUrl = UrlNormalizer::normalizeAppUrl($url);

        if ($normalizedUrl === null) {
            return null;
        }

        $parts = parse_url($normalizedUrl);

        if ($parts === false) {
            return null;
        }

        /** @var array<string, int|string> $parts */
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path !== '' ? $path : '/', $query);
    }

    private static function extractDownloadSuggestionDomain(string $normalizedUrl): ?string
    {
        $parts = parse_url($normalizedUrl);

        if ($parts === false) {
            return null;
        }

        /** @var array<string, int|string> $parts */
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';

        return sprintf('%s://%s%s/', $scheme, $host, $port);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{value: string, usage_count: int}>
     */
    private static function sortDownloadSuggestionCounts(array $counts): array
    {
        $suggestions = [];

        foreach ($counts as $value => $usageCount) {
            $suggestions[] = [
                'value' => $value,
                'usage_count' => $usageCount,
            ];
        }

        usort(
            $suggestions,
            static fn (array $left, array $right): int => ($right['usage_count'] <=> $left['usage_count'])
                ?: ($left['value'] <=> $right['value'])
        );

        return $suggestions;
    }
}
