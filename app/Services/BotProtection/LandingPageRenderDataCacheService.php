<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LandingPageRenderDataCacheService
{
    use ChecksCacheTagging;

    /**
     * @param  Closure(): array{template: string, props: array<string, mixed>}  $resolver
     * @return array{template: string, props: array<string, mixed>}
     */
    public function remember(LandingPage $landingPage, Closure $resolver): array
    {
        if (! $this->shouldCache($landingPage)) {
            return $resolver();
        }

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA;

        /** @var array{template: string, props: array<string, mixed>} */
        return $this->getCacheInstance($cacheKey->tags())
            ->remember($cacheKey->key($landingPage->id), $cacheKey->ttl(), $resolver);
    }

    public function forget(LandingPage $landingPage): bool
    {
        return CacheKey::LANDING_PAGE_RENDER_DATA->forget($landingPage->id);
    }

    public function forgetById(int $landingPageId): bool
    {
        return CacheKey::LANDING_PAGE_RENDER_DATA->forget($landingPageId);
    }

    public function forgetForTemplate(LandingPageTemplate $template): void
    {
        $query = LandingPage::query()
            ->select('landing_pages.id')
            ->where('is_published', true);

        if ($template->isDefault()) {
            $query->whereDoesntHave('landingPageTemplate', function (Builder $query) use ($template): void {
                $query->where('is_default', false)
                    ->where('template_type', $template->template_type);
            });

            if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
                $query->whereHas('resource.resourceType', function (Builder $query): void {
                    $query->where('slug', 'physical-object');
                });
            } else {
                $query->whereDoesntHave('resource.resourceType', function (Builder $query): void {
                    $query->where('slug', 'physical-object');
                });
            }
        } else {
            $query->where('landing_page_template_id', $template->id);
        }

        $query->chunkById(500, function (Collection $landingPages): void {
            foreach ($landingPages as $landingPage) {
                $this->forgetById((int) $landingPage->id);
            }
        }, 'landing_pages.id', 'id');
    }

    private function shouldCache(LandingPage $landingPage): bool
    {
        return (bool) config('bot_protection.enabled', true)
            && CacheKey::LANDING_PAGE_RENDER_DATA->ttl() > 0
            && $landingPage->isPublished();
    }
}
