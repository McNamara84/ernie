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
     * @param  list<array{url: string, label?: string|null, visible?: string|null}>  $fileEntries
     */
    public function createForResource(
        Resource $resource,
        array $fileEntries,
        bool $isPublished,
        bool $createWhenEmpty = false,
    ): ?LandingPage {
        if (LandingPage::where('resource_id', $resource->id)->exists()) {
            return LandingPage::where('resource_id', $resource->id)->first();
        }

        $fileEntries = $this->normaliseFileEntries($fileEntries);

        if ($fileEntries === [] && ! $createWhenEmpty) {
            return null;
        }

        $resource->loadMissing('titles.titleType');

        $landingPage = DB::transaction(function () use ($resource, $fileEntries, $isPublished): LandingPage {
            $primaryFile = $fileEntries[0] ?? null;
            $shouldPublish = $isPublished && $primaryFile !== null;

            $landingPage = new LandingPage([
                'resource_id' => $resource->id,
                'template' => 'default_gfz',
                'ftp_url' => $primaryFile['url'] ?? null,
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

            return $landingPage;
        });

        CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget();

        return $landingPage;
    }

    /**
     * @param  list<array{url: string, label?: string|null, visible?: string|null}>  $fileEntries
     * @return list<array{url: string, label: string|null, visible: string|null}>
     */
    private function normaliseFileEntries(array $fileEntries): array
    {
        $normalised = [];
        $seenUrls = [];

        foreach ($fileEntries as $entry) {
            $url = trim($entry['url'] ?? '');

            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;

            $label = isset($entry['label']) ? trim((string) $entry['label']) : '';

            $normalised[] = [
                'url' => $url,
                'label' => $label !== '' ? mb_substr($label, 0, 255) : null,
                'visible' => isset($entry['visible']) ? (string) $entry['visible'] : null,
            ];
        }

        return $normalised;
    }
}
