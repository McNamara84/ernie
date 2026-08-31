<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPageTemplate;

final class LandingPageDocumentMetadataService
{
    private const SITE_NAME = 'GFZ Data Services';

    private const UNTITLED = 'Untitled';

    private const PREVIEW_PREFIX = 'Preview: ';

    private const PREVIEW_ROBOTS = 'noindex, nofollow';

    /**
     * @param  array<string, mixed>  $resourceData
     * @return array{title: string, robots: string|null}
     */
    public function resolve(array $resourceData, string $effectiveTemplate, bool $isPreview): array
    {
        $contentTitle = $this->mainTitle($resourceData['titles'] ?? null);

        if ($effectiveTemplate === LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG
            && strcasecmp(trim($contentTitle), ':tba') === 0) {
            $localName = $this->igsnLocalName($resourceData['igsn_metadata'] ?? null);

            if ($localName !== null) {
                $contentTitle = $localName;
            }
        }

        $prefix = $isPreview ? self::PREVIEW_PREFIX : '';

        return [
            'title' => $prefix.$contentTitle.' | '.self::SITE_NAME,
            'robots' => $isPreview ? self::PREVIEW_ROBOTS : null,
        ];
    }

    private function mainTitle(mixed $titles): string
    {
        if (! is_array($titles)) {
            return self::UNTITLED;
        }

        foreach ($titles as $title) {
            if (! is_array($title)) {
                continue;
            }

            $titleType = $title['title_type'] ?? null;
            if ($titleType !== null && $titleType !== '' && $titleType !== 'MainTitle') {
                continue;
            }

            $value = $title['title'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return self::UNTITLED;
    }

    private function igsnLocalName(mixed $igsnMetadata): ?string
    {
        if (! is_array($igsnMetadata)) {
            return null;
        }

        $name = $igsnMetadata['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }
}
