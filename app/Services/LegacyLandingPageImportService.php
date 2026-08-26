<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

class LegacyLandingPageImportService
{
    /**
     * Create a default landing page from legacy file entries.
     *
     * The first URL becomes the primary download URL. Subsequent URLs are stored
     * as landing page links with legacy names/descriptions as labels.
     *
     * @param  list<array{url: string, label?: string|null, source_name?: string|null, visible?: string|null}>  $fileEntries
     */
    public function createForResource(
        Resource $resource,
        array $fileEntries,
        bool $isPublished,
        bool $createWhenEmpty = false,
    ): ?LandingPage {
        $existingLandingPage = LandingPage::where('resource_id', $resource->id)->first();

        if ($existingLandingPage !== null) {
            return $existingLandingPage;
        }

        $fileEntries = $this->normaliseFileEntries($fileEntries);

        if ($fileEntries === [] && ! $createWhenEmpty) {
            return null;
        }

        $landingPage = DB::transaction(
            fn (): LandingPage => $this->createDefaultLandingPage($resource, $fileEntries, $isPublished)
        );

        CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget();

        return $landingPage;
    }

    /**
     * Synchronise missing legacy file entries into an existing or new landing page.
     *
     * Existing curator-provided values are preserved: a non-empty primary download
     * URL is never overwritten and existing additional links are kept. A released
     * legacy resource may publish an existing draft page, but this method never
     * unpublishes an existing page.
     *
     * @param  list<array{url: string, label?: string|null, source_name?: string|null, visible?: string|null}>  $fileEntries
     * @return array{changed: bool, created: bool, ftp_url_added: bool, links_added: int, labels_updated: int, landing_page: LandingPage|null}
     */
    public function syncMissingFileEntries(
        Resource $resource,
        array $fileEntries,
        bool $isPublished,
        bool $createWhenEmpty = false,
    ): array {
        $fileEntries = $this->normaliseFileEntries($fileEntries);

        if ($fileEntries === [] && ! $createWhenEmpty) {
            return $this->syncResult();
        }

        $result = DB::transaction(function () use ($resource, $fileEntries, $isPublished): array {
            $landingPage = LandingPage::where('resource_id', $resource->id)
                ->lockForUpdate()
                ->first();

            if ($landingPage === null) {
                return $this->syncResult(
                    changed: true,
                    created: true,
                    ftpUrlAdded: $fileEntries !== [],
                    linksAdded: max(count($fileEntries) - 1, 0),
                    landingPage: $this->createDefaultLandingPage($resource, $fileEntries, $isPublished),
                );
            }

            if ($landingPage->isExternal()) {
                return $this->syncResult(landingPage: $landingPage);
            }

            $published = false;

            if ($isPublished && ! $landingPage->is_published) {
                $landingPage->forceFill([
                    'is_published' => true,
                    'published_at' => $landingPage->published_at ?? now(),
                ])->save();
                $published = true;
                $landingPage->refresh();
            }

            if ($fileEntries === []) {
                return $this->syncResult(changed: $published, landingPage: $landingPage);
            }

            $landingPage->loadMissing('links');

            $ftpUrlAdded = false;
            $downloadsUnavailableCleared = false;
            $labelsUpdated = 0;
            $primaryFile = $fileEntries[0];

            if ($this->isBlank($landingPage->ftp_url)) {
                $landingPage->forceFill([
                    'ftp_url' => $primaryFile['url'],
                    'downloads_unavailable' => false,
                ])->save();
                $ftpUrlAdded = $landingPage->wasChanged('ftp_url');
                $downloadsUnavailableCleared = $landingPage->wasChanged('downloads_unavailable');
                $landingPage->refresh()->load('links');
            } elseif ($landingPage->downloads_unavailable) {
                $landingPage->forceFill(['downloads_unavailable' => false])->save();
                $downloadsUnavailableCleared = $landingPage->wasChanged('downloads_unavailable');
                $landingPage->refresh()->load('links');
            }

            if ($this->sameUrl($landingPage->ftp_url, $primaryFile['url'])
                && ! $this->isBlank($primaryFile['label'])
                && $this->canReplaceImportedLabel(
                    (string) $landingPage->primary_download_label,
                    $primaryFile['url'],
                    $primaryFile['source_name'],
                )) {
                $landingPage->forceFill(['primary_download_label' => $primaryFile['label']])->save();
                if ($landingPage->wasChanged('primary_download_label')) {
                    $labelsUpdated++;
                }
                $landingPage->refresh()->load('links');
            }

            $existingUrls = $this->existingLandingPageUrls($landingPage);
            $nextPosition = $this->nextLinkPosition($landingPage);
            $linksAdded = 0;

            foreach ($fileEntries as $fileEntry) {
                if (isset($existingUrls[$fileEntry['url']])) {
                    $existingLink = $landingPage->links->first(
                        fn ($link): bool => $this->sameUrl($link->url, $fileEntry['url'])
                    );

                    if ($existingLink !== null
                        && ! $this->isBlank($fileEntry['label'])
                        && $this->canReplaceImportedLabel(
                            (string) $existingLink->label,
                            $fileEntry['url'],
                            $fileEntry['source_name'],
                        )) {
                        $existingLink->forceFill(['label' => $fileEntry['label']])->save();
                        if ($existingLink->wasChanged('label')) {
                            $labelsUpdated++;
                        }
                    }

                    continue;
                }

                $landingPage->links()->create([
                    'url' => $fileEntry['url'],
                    'label' => $fileEntry['label'] ?? 'Download '.($nextPosition + 2),
                    'position' => $nextPosition,
                ]);

                $existingUrls[$fileEntry['url']] = true;
                $nextPosition++;
                $linksAdded++;
            }

            return $this->syncResult(
                changed: $published || $ftpUrlAdded || $downloadsUnavailableCleared || $linksAdded > 0 || $labelsUpdated > 0,
                ftpUrlAdded: $ftpUrlAdded,
                linksAdded: $linksAdded,
                labelsUpdated: $labelsUpdated,
                landingPage: $landingPage->fresh(['links']),
            );
        });

        if ($result['changed']) {
            CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget();
        }

        return $result;
    }

    /**
     * @param  list<array{url: string, label: string|null, source_name: string|null, visible: string|null}>  $fileEntries
     */
    private function createDefaultLandingPage(Resource $resource, array $fileEntries, bool $isPublished): LandingPage
    {
        $resource->loadMissing('titles.titleType');

        $primaryFile = $fileEntries[0] ?? null;
        $shouldPublish = $isPublished;

        $landingPage = new LandingPage([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'ftp_url' => $primaryFile['url'] ?? null,
            'primary_download_label' => $primaryFile['label'] ?? null,
            'downloads_unavailable' => $primaryFile === null,
            'is_published' => $shouldPublish,
            'published_at' => $shouldPublish ? now() : null,
        ]);

        $landingPage->setRelation('resource', $resource);
        $landingPage->save();

        foreach (array_slice($fileEntries, 1) as $position => $fileEntry) {
            $landingPage->links()->create([
                'url' => $fileEntry['url'],
                'label' => $fileEntry['label'] ?? 'Download '.($position + 2),
                'position' => $position,
            ]);
        }

        return $landingPage->fresh(['links']) ?? $landingPage;
    }

    /**
     * @param  list<array{url: string, label?: string|null, source_name?: string|null, visible?: string|null}>  $fileEntries
     * @return list<array{url: string, label: string|null, source_name: string|null, visible: string|null}>
     */
    private function normaliseFileEntries(array $fileEntries): array
    {
        $normalised = [];
        $seenUrls = [];

        foreach ($fileEntries as $entry) {
            $url = trim($entry['url']);

            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            $label = isset($entry['label']) ? trim((string) $entry['label']) : '';
            $sourceName = isset($entry['source_name']) ? trim((string) $entry['source_name']) : '';

            $normalised[] = [
                'url' => $url,
                'label' => $label !== '' ? mb_substr($label, 0, 255) : null,
                'source_name' => $sourceName !== '' ? mb_substr($sourceName, 0, 255) : null,
                'visible' => isset($entry['visible']) ? (string) $entry['visible'] : null,
            ];
        }

        return $normalised;
    }

    /**
     * @return array<string, true>
     */
    private function existingLandingPageUrls(LandingPage $landingPage): array
    {
        $urls = [];

        if (! $this->isBlank($landingPage->ftp_url)) {
            $urls[trim((string) $landingPage->ftp_url)] = true;
        }

        foreach ($landingPage->links as $link) {
            $url = trim((string) $link->url);

            if ($url !== '') {
                $urls[$url] = true;
            }
        }

        return $urls;
    }

    private function nextLinkPosition(LandingPage $landingPage): int
    {
        $maxPosition = $landingPage->links->max('position');

        return is_numeric($maxPosition) ? ((int) $maxPosition) + 1 : 0;
    }

    private function isBlank(?string $value): bool
    {
        return trim((string) $value) === '';
    }

    private function sameUrl(?string $left, ?string $right): bool
    {
        return trim((string) $left) !== ''
            && trim((string) $left) === trim((string) $right);
    }

    private function canReplaceImportedLabel(string $currentLabel, string $url, ?string $sourceName): bool
    {
        $currentLabel = trim($currentLabel);

        return $currentLabel === ''
            || $currentLabel === trim($url)
            || ($sourceName !== null && $currentLabel === trim($sourceName))
            || preg_match('/\ADownload (?:\(\d+\)|\d+)\z/', $currentLabel) === 1;
    }

    /**
     * @return array{changed: bool, created: bool, ftp_url_added: bool, links_added: int, labels_updated: int, landing_page: LandingPage|null}
     */
    private function syncResult(
        bool $changed = false,
        bool $created = false,
        bool $ftpUrlAdded = false,
        int $linksAdded = 0,
        int $labelsUpdated = 0,
        ?LandingPage $landingPage = null,
    ): array {
        return [
            'changed' => $changed,
            'created' => $created,
            'ftp_url_added' => $ftpUrlAdded,
            'links_added' => $linksAdded,
            'labels_updated' => $labelsUpdated,
            'landing_page' => $landingPage,
        ];
    }
}
