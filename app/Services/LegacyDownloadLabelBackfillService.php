<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LegacyDownloadLabelBackfillService
{
    public function __construct(private readonly MetaworksDownloadUrlService $legacyFiles) {}

    /**
     * @param  list<string>  $dois
     * @return array{
     *     scanned: int,
     *     primary_labels_updated: int,
     *     file_labels_updated: int,
     *     link_labels_updated: int,
     *     preserved_labels: int,
     *     unmatched_urls: int,
     *     errors: int,
     *     records: list<array{landing_page_id: int, resource_id: int, doi: string, status: string, message: string}>
     * }
     */
    public function run(
        bool $apply,
        int $afterId = 0,
        int $limit = 0,
        array $dois = [],
    ): array {
        $result = [
            'scanned' => 0,
            'primary_labels_updated' => 0,
            'file_labels_updated' => 0,
            'link_labels_updated' => 0,
            'preserved_labels' => 0,
            'unmatched_urls' => 0,
            'errors' => 0,
            'records' => [],
        ];

        $normalizedDois = collect($dois)
            ->map(static fn (string $doi): string => trim($doi))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $baseQuery = LandingPage::query()
            ->where('id', '>', max(0, $afterId))
            ->where('template', '!=', 'external')
            ->whereHas('resource', static function (Builder $query) use ($normalizedDois): void {
                $query->whereNotNull('doi')->where('doi', '!=', '');
                if ($normalizedDois !== []) {
                    $query->whereIn('doi', $normalizedDois);
                }
            });

        $query = clone $baseQuery;
        if ($limit > 0) {
            $ids = (clone $baseQuery)->orderBy('id')->limit($limit)->pluck('id');
            $query = LandingPage::query()->whereKey($ids);
        }

        $legacyUnavailable = false;

        $relations = $apply
            ? ['resource:id,doi']
            : ['resource:id,doi', 'files', 'links'];

        $query->with($relations)
            ->orderBy('id')
            ->chunkById(100, function ($landingPages) use ($apply, &$legacyUnavailable, &$result): bool {
                foreach ($landingPages as $landingPage) {
                    $doi = trim((string) $landingPage->resource->doi);
                    if ($doi === '') {
                        continue;
                    }

                    $result['scanned']++;

                    try {
                        $fileResult = $this->legacyFiles->lookupFileEntries($doi);
                    } catch (Throwable $exception) {
                        $result['errors']++;
                        $result['records'][] = $this->record(
                            $landingPage,
                            $doi,
                            'error',
                            'Legacy database lookup failed: '.$exception->getMessage(),
                        );
                        $legacyUnavailable = true;

                        return false;
                    }

                    $entriesByUrl = [];
                    foreach ($fileResult['files'] as $entry) {
                        $url = trim($entry['url']);
                        if ($url !== '' && ! isset($entriesByUrl[$url])) {
                            $entriesByUrl[$url] = $entry;
                        }
                    }

                    if ($entriesByUrl === []) {
                        $result['records'][] = $this->record($landingPage, $doi, 'unchanged', 'No valid legacy file entries found.');

                        continue;
                    }

                    $changes = $apply
                        ? DB::transaction(function () use ($landingPage, $entriesByUrl): array {
                            $lockedLandingPage = LandingPage::query()
                                ->lockForUpdate()
                                ->findOrFail($landingPage->id);

                            $lockedLandingPage->setRelation(
                                'files',
                                $lockedLandingPage->files()->lockForUpdate()->get(),
                            );
                            $lockedLandingPage->setRelation(
                                'links',
                                $lockedLandingPage->links()->lockForUpdate()->get(),
                            );

                            return $this->updateLandingPage($lockedLandingPage, $entriesByUrl, true);
                        })
                        : $this->updateLandingPage($landingPage, $entriesByUrl, false);

                    foreach (['primary_labels_updated', 'file_labels_updated', 'link_labels_updated', 'preserved_labels', 'unmatched_urls'] as $key) {
                        $result[$key] += $changes[$key];
                    }

                    $changed = $changes['primary_labels_updated'] + $changes['file_labels_updated'] + $changes['link_labels_updated'];
                    $result['records'][] = $this->record(
                        $landingPage,
                        $doi,
                        $changed > 0 ? ($apply ? 'updated' : 'would_update') : 'unchanged',
                        sprintf(
                            'primary=%d, files=%d, links=%d, preserved=%d, unmatched=%d',
                            $changes['primary_labels_updated'],
                            $changes['file_labels_updated'],
                            $changes['link_labels_updated'],
                            $changes['preserved_labels'],
                            $changes['unmatched_urls'],
                        ),
                    );
                }

                return ! $legacyUnavailable;
            });

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entriesByUrl
     * @return array{primary_labels_updated: int, file_labels_updated: int, link_labels_updated: int, preserved_labels: int, unmatched_urls: int}
     */
    private function updateLandingPage(LandingPage $landingPage, array $entriesByUrl, bool $apply): array
    {
        $changes = [
            'primary_labels_updated' => 0,
            'file_labels_updated' => 0,
            'link_labels_updated' => 0,
            'preserved_labels' => 0,
            'unmatched_urls' => 0,
        ];

        $primaryUrl = trim((string) $landingPage->ftp_url);
        if ($primaryUrl !== '') {
            $entry = $entriesByUrl[$primaryUrl] ?? null;
            if ($entry === null) {
                $changes['unmatched_urls']++;
            } else {
                $this->proposeLabelUpdate(
                    currentLabel: $landingPage->primary_download_label,
                    targetLabel: $entry['label'] ?? null,
                    url: $primaryUrl,
                    sourceName: $entry['source_name'] ?? null,
                    onUpdate: function (string $label) use ($apply, $landingPage): void {
                        if ($apply) {
                            $landingPage->forceFill(['primary_download_label' => $label])->save();
                        }
                    },
                    updatedCounter: $changes['primary_labels_updated'],
                    preservedCounter: $changes['preserved_labels'],
                );
            }
        }

        foreach ($landingPage->files as $file) {
            $url = trim((string) $file->url);
            $entry = $entriesByUrl[$url] ?? null;
            if ($entry === null) {
                $changes['unmatched_urls']++;

                continue;
            }

            $this->proposeLabelUpdate(
                currentLabel: $file->label,
                targetLabel: $entry['label'] ?? null,
                url: $url,
                sourceName: $entry['source_name'] ?? null,
                onUpdate: function (string $label) use ($apply, $file): void {
                    if ($apply) {
                        $file->forceFill(['label' => $label])->save();
                    }
                },
                updatedCounter: $changes['file_labels_updated'],
                preservedCounter: $changes['preserved_labels'],
            );
        }

        foreach ($landingPage->links as $link) {
            $url = trim((string) $link->url);
            $entry = $entriesByUrl[$url] ?? null;
            if ($entry === null) {
                continue;
            }

            $this->proposeLabelUpdate(
                currentLabel: $link->label,
                targetLabel: $entry['label'] ?? null,
                url: $url,
                sourceName: $entry['source_name'] ?? null,
                onUpdate: function (string $label) use ($apply, $link): void {
                    if ($apply) {
                        $link->forceFill(['label' => $label])->save();
                    }
                },
                updatedCounter: $changes['link_labels_updated'],
                preservedCounter: $changes['preserved_labels'],
            );
        }

        return $changes;
    }

    private function proposeLabelUpdate(
        mixed $currentLabel,
        mixed $targetLabel,
        string $url,
        mixed $sourceName,
        callable $onUpdate,
        int &$updatedCounter,
        int &$preservedCounter,
    ): void {
        $target = is_string($targetLabel) ? trim($targetLabel) : '';
        if ($target === '') {
            return;
        }

        $current = is_string($currentLabel) ? trim($currentLabel) : '';
        if ($current === $target) {
            return;
        }

        if (! $this->canReplaceLabel($current, $url, $sourceName)) {
            $preservedCounter++;

            return;
        }

        $updatedCounter++;
        $onUpdate($target);
    }

    private function canReplaceLabel(string $currentLabel, string $url, mixed $sourceName): bool
    {
        $legacyName = is_string($sourceName) ? trim($sourceName) : '';

        return $currentLabel === ''
            || $currentLabel === trim($url)
            || ($legacyName !== '' && $currentLabel === $legacyName)
            || preg_match('/\ADownload (?:\(\d+\)|\d+)\z/', $currentLabel) === 1;
    }

    /** @return array{landing_page_id: int, resource_id: int, doi: string, status: string, message: string} */
    private function record(LandingPage $landingPage, string $doi, string $status, string $message): array
    {
        return [
            'landing_page_id' => (int) $landingPage->id,
            'resource_id' => (int) $landingPage->resource_id,
            'doi' => $doi,
            'status' => $status,
            'message' => $message,
        ];
    }
}
