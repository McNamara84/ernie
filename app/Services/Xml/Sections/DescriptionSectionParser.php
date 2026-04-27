<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<descriptions>/<description>` elements from a DataCite XML document.
 */
final readonly class DescriptionSectionParser
{
    /**
     * @return array<int, array{type: string, description: string, language: string|null}>
     */
    public function parse(XmlReader $reader): array
    {
        $descriptionElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="descriptions"]/*[local-name()="description"]')
            ->get();

        $descriptions = [];

        foreach ($descriptionElements as $element) {
            $descriptionType = $element->getAttribute('descriptionType');
            $description = $element->getContent();

            if (! is_string($description) || trim($description) === '') {
                continue;
            }

            $descLang = $element->getAttribute('xml:lang');

            $descriptions[] = [
                'type' => is_string($descriptionType) && $descriptionType !== '' ? $descriptionType : 'Other',
                'description' => $description,
                'language' => is_string($descLang) && trim($descLang) !== '' ? trim($descLang) : null,
            ];
        }

        return $descriptions;
    }
}
