<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Description;
use App\Models\Resource;
use App\Support\LanguageTag;

/** Selects the best available Abstract for a resource's preferred language. */
final class PreferredAbstractResolverService
{
    public function resolve(Resource $resource): ?string
    {
        if (! $resource->relationLoaded('descriptions')) {
            return null;
        }

        $preferredLanguage = LanguageTag::normalize($resource->language?->code);
        $abstract = $resource->descriptions
            ->filter(
                fn (Description $description): bool => $description->isAbstract()
                    && trim((string) $description->value) !== '',
            )
            ->sortBy(function (Description $description) use ($preferredLanguage): string {
                $language = LanguageTag::normalize($description->language);
                $primaryLanguage = LanguageTag::primarySubtag($language);
                $rank = match (true) {
                    $language !== null && $language === $preferredLanguage => 0,
                    $primaryLanguage === 'en' => 1,
                    $primaryLanguage === 'de' => 2,
                    $language !== null => 3,
                    default => 4,
                };

                return sprintf('%d-%020d', $rank, $description->id);
            })
            ->first();

        if ($abstract === null) {
            return null;
        }

        $value = trim((string) $abstract->value);

        return $value !== '' ? $value : null;
    }
}
