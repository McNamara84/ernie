<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Support\UriHelper;
use Illuminate\Database\Eloquent\Collection;

/**
 * Separate historical suppression from the absence of download sources.
 * The stored downloads_unavailable column is only a legacy suppression flag.
 */
final class LandingPageDownloadAvailabilityService
{
    /** Effective source availability; publication, template and embargo policies remain with callers. */
    public function isAvailable(LandingPage $landingPage): bool
    {
        if ($landingPage->is_tombstone) {
            return false;
        }

        $landingPage->loadMissing(['files', 'links']);

        return ! $this->requiresActivation($landingPage)
            && $this->hasSources($landingPage->ftp_url, $landingPage->files);
    }

    /** @return Collection<int, LandingPageFile> */
    public function usableFiles(LandingPage $landingPage): Collection
    {
        $landingPage->loadMissing('files');

        return $landingPage->files
            ->filter(fn (LandingPageFile $file): bool => $this->isUsableUrl($file->url))
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();
    }

    public function requiresActivation(LandingPage $landingPage): bool
    {
        return $landingPage->downloads_unavailable && $this->hasRetainedValues($landingPage);
    }

    public function hasRetainedValues(LandingPage $landingPage): bool
    {
        if (trim((string) $landingPage->ftp_url) !== '') {
            return true;
        }

        $landingPage->loadMissing(['files', 'links']);

        // Preserve even malformed historical values until explicitly released.
        return $landingPage->files->contains(fn (LandingPageFile $file): bool => trim($file->url) !== '')
            || $landingPage->links->contains(fn (LandingPageLink $link): bool => trim($link->url) !== '');
    }

    /** Call before applying new URLs, while holding the landing page lock. */
    public function normalizeEmptySuppression(LandingPage $landingPage): void
    {
        if ($landingPage->downloads_unavailable && ! $this->hasRetainedValues($landingPage)) {
            $landingPage->downloads_unavailable = false;
        }
    }

    /** @param iterable<mixed> $files */
    public function hasSources(mixed $primaryUrl, iterable $files): bool
    {
        if ($this->isUsableUrl($primaryUrl)) {
            return true;
        }

        foreach ($files as $file) {
            if ($this->isUsableUrl($file instanceof LandingPageFile ? $file->url : (is_array($file) ? ($file['url'] ?? null) : null))) {
                return true;
            }
        }

        return false;
    }

    public function isUsableUrl(mixed $url): bool
    {
        return is_string($url) && trim($url) !== ''
            && UriHelper::isHttpUrl(trim($url))
            && (UriHelper::getHost(trim($url)) ?? '') !== '';
    }
}
