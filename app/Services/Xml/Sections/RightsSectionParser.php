<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<rightsList>/<rights>` into the licenses payload (list of rightsIdentifier).
 */
final readonly class RightsSectionParser
{
    /**
     * @return array<int, string>
     */
    public function parse(XmlReader $reader): array
    {
        $rightsElements = $reader
            ->xpathElement('//*[local-name()="rightsList"]/*[local-name()="rights"]')
            ->get();

        $licenses = [];

        foreach ($rightsElements as $element) {
            $identifier = $element->getAttribute('rightsIdentifier');
            if (is_string($identifier) && $identifier !== '') {
                $licenses[] = $identifier;
            }
        }

        return $licenses;
    }
}
