<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Illuminate\Support\Str;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<titles>/<title>` into the title payload, with main titles sorted first.
 */
final readonly class TitleSectionParser
{
    /**
     * @return array<int, array{title: mixed, titleType: string, language: string|null}>
     */
    public function parse(XmlReader $reader): array
    {
        $titleElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="titles"]/*[local-name()="title"]')
            ->get();

        $titles = [];

        foreach ($titleElements as $element) {
            $titleType = $element->getAttribute('titleType');
            $xmlLang = $element->getAttribute('xml:lang');
            $titles[] = [
                'title' => $element->getContent(),
                'titleType' => is_string($titleType) && $titleType !== ''
                    ? Str::kebab($titleType)
                    : 'main-title',
                'language' => is_string($xmlLang) && trim($xmlLang) !== '' ? trim($xmlLang) : null,
            ];
        }

        $mainTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] === 'main-title'
        ));
        $otherTitles = array_values(array_filter(
            $titles,
            fn ($t) => $t['titleType'] !== 'main-title'
        ));

        return array_merge($mainTitles, $otherTitles);
    }
}
