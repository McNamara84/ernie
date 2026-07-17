<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Builds FAIR guidance context exclusively from already eager-loaded state.
 */
final class FairImprovementContextFactory
{
    public function fromResource(
        Resource $resource,
        ?DateTimeInterface $assessedAt,
        ?string $assessedIdentifier = null,
        bool $machineReadableDistributionVerified = false,
    ): FairImprovementContext {
        $this->requireLoaded($resource, 'landingPage');
        $this->requireLoaded($resource, 'igsnMetadata');

        $landingPageRelation = $resource->getRelation('landingPage');
        $igsnMetadataRelation = $resource->getRelation('igsnMetadata');

        $landingPage = $landingPageRelation instanceof LandingPage ? $landingPageRelation : null;
        $igsnMetadata = $igsnMetadataRelation instanceof IgsnMetadata ? $igsnMetadataRelation : null;

        $files = new Collection;
        $links = new Collection;
        $externalDomain = null;

        if ($landingPage !== null) {
            $this->requireLoaded($landingPage, 'files');
            $this->requireLoaded($landingPage, 'links');
            $this->requireLoaded($landingPage, 'externalDomain');

            $filesRelation = $landingPage->getRelation('files');
            $linksRelation = $landingPage->getRelation('links');
            $domainRelation = $landingPage->getRelation('externalDomain');

            if ($filesRelation instanceof Collection) {
                $files = $filesRelation;
            }

            if ($linksRelation instanceof Collection) {
                $links = $linksRelation;
            }

            if ($domainRelation instanceof LandingPageDomain) {
                $externalDomain = $domainRelation;
            }
        }

        $hasConfiguredDownloads = $landingPage !== null
            && ! $landingPage->downloads_unavailable
            && (
                $this->filled($landingPage->ftp_url)
                || $files->contains(fn (mixed $file): bool => $file instanceof LandingPageFile && $this->filled($file->url))
                || $links->contains(fn (mixed $link): bool => $link instanceof LandingPageLink && $this->filled($link->url))
            );

        return new FairImprovementContext(
            hasDoi: $this->filled($resource->doi),
            landingPageExists: $landingPage !== null,
            landingPagePublished: $landingPage?->isPublished() ?? false,
            landingPageIsInternal: $landingPage !== null && ! $landingPage->isExternal(),
            landingPageUsesHttps: $this->landingPageUsesHttps($landingPage, $externalDomain),
            hasConfiguredDownloads: $hasConfiguredDownloads,
            hasIgsnMetadata: $igsnMetadata !== null,
            igsnRegistered: $igsnMetadata?->isRegistered() ?? false,
            machineReadableDistributionVerified: $machineReadableDistributionVerified,
            currentIdentifier: $resource->doi,
            assessedIdentifier: $assessedIdentifier,
            assessedAt: $assessedAt,
            latestRelevantChangeAt: $this->latestRelevantChangeAt(
                $resource,
                $landingPage,
                $externalDomain,
                $files,
                $links,
                $igsnMetadata,
            ),
        );
    }

    private function requireLoaded(Resource|LandingPage $model, string $relation): void
    {
        if (! $model->relationLoaded($relation)) {
            throw new LogicException(sprintf(
                '%s must be eager-loaded before FAIR improvement context is built.',
                $relation,
            ));
        }
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function landingPageUsesHttps(
        ?LandingPage $landingPage,
        ?LandingPageDomain $externalDomain,
    ): bool {
        if ($landingPage === null) {
            return false;
        }

        $target = $landingPage->isExternal()
            ? $externalDomain?->domain
            : config('app.url');

        if (! is_string($target)) {
            return false;
        }

        return strtolower((string) parse_url($target, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * @param  Collection<int, LandingPageFile>  $files
     * @param  Collection<int, LandingPageLink>  $links
     */
    private function latestRelevantChangeAt(
        Resource $resource,
        ?LandingPage $landingPage,
        ?LandingPageDomain $externalDomain,
        Collection $files,
        Collection $links,
        ?IgsnMetadata $igsnMetadata,
    ): ?DateTimeInterface {
        $timestamps = [
            $resource->updated_at,
            $landingPage?->updated_at,
            $landingPage?->published_at,
            $externalDomain?->updated_at,
            $igsnMetadata?->updated_at,
        ];

        foreach ($files as $file) {
            $timestamps[] = $file->updated_at;
        }

        foreach ($links as $link) {
            $timestamps[] = $link->updated_at;
        }

        $latest = null;

        foreach ($timestamps as $timestamp) {
            if (! $timestamp instanceof DateTimeInterface) {
                continue;
            }

            if (
                $latest === null
                || (float) $timestamp->format('U.u') > (float) $latest->format('U.u')
            ) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }
}
