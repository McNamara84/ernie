<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;

/**
 * Resolves the effective database-backed landing-page template.
 *
 * A null explicit template means "automatic": regular resources inherit from
 * their datacenter and all other resources fall back to their system default.
 */
final class LandingPageTemplateResolver
{
    public const SOURCE_EXPLICIT = 'explicit';

    public const SOURCE_DATACENTER = 'datacenter';

    public const SOURCE_DEFAULT = 'default';

    /**
     * @return array{template: LandingPageTemplate, source: 'explicit'|'datacenter'|'default'}
     */
    public function forLandingPage(Resource $resource, LandingPage $landingPage): array
    {
        return $this->resolve($resource, $landingPage->landing_page_template_id);
    }

    /**
     * @return array{template: LandingPageTemplate, source: 'explicit'|'datacenter'|'default'}
     */
    public function automatic(Resource $resource): array
    {
        return $this->resolve($resource, null);
    }

    /**
     * @return array{template: LandingPageTemplate, source: 'explicit'|'datacenter'|'default'}
     */
    public function resolve(Resource $resource, LandingPageTemplate|int|null $explicitTemplate): array
    {
        $resource->loadMissing(['resourceType:id,slug', 'datacenter.landingPageTemplate']);
        $expectedType = LandingPageTemplate::expectedTemplateTypeForResource($resource->resourceType?->slug);

        $explicit = $this->findCompatible($explicitTemplate, $expectedType);
        if ($explicit !== null) {
            return ['template' => $explicit, 'source' => self::SOURCE_EXPLICIT];
        }

        if ($expectedType === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE) {
            $inherited = $resource->datacenter?->landingPageTemplate;
            if ($inherited !== null
                && $inherited->template_type === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE) {
                return ['template' => $inherited, 'source' => self::SOURCE_DATACENTER];
            }
        }

        return [
            'template' => LandingPageTemplate::defaultForType($expectedType),
            'source' => self::SOURCE_DEFAULT,
        ];
    }

    private function findCompatible(LandingPageTemplate|int|null $templateOrId, string $expectedType): ?LandingPageTemplate
    {
        if ($templateOrId === null) {
            return null;
        }

        $template = $templateOrId instanceof LandingPageTemplate
            ? $templateOrId
            : LandingPageTemplate::query()->find($templateOrId);

        return $template?->template_type === $expectedType ? $template : null;
    }
}
