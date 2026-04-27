<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\Xml\XmlElementHelpers;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<fundingReferences>/<fundingReference>` elements from a DataCite XML document.
 */
final readonly class FundingReferenceSectionParser
{
    /**
     * @return array<int, array{
     *     funderName: string,
     *     funderIdentifier: string|null,
     *     funderIdentifierType: string|null,
     *     awardNumber: string|null,
     *     awardUri: string|null,
     *     awardTitle: string|null,
     * }>
     */
    public function parse(XmlReader $reader): array
    {
        $fundingElements = $reader
            ->xpathElement('//*[local-name()="fundingReferences"]/*[local-name()="fundingReference"]')
            ->get();

        $fundingReferences = [];

        foreach ($fundingElements as $fundingElement) {
            $content = $fundingElement->getContent();

            if (! is_array($content)) {
                continue;
            }

            $funderNameElement = XmlElementHelpers::firstElementByKey($content, 'funderName');
            $funderName = XmlElementHelpers::stringValue($funderNameElement);

            if (! is_string($funderName) || $funderName === '') {
                continue;
            }

            $funderIdentifierElement = XmlElementHelpers::firstElementByKey($content, 'funderIdentifier');
            $funderIdentifier = XmlElementHelpers::stringValue($funderIdentifierElement);
            $funderIdentifierType = $funderIdentifierElement?->getAttribute('funderIdentifierType');

            $awardNumberElement = XmlElementHelpers::firstElementByKey($content, 'awardNumber');
            $awardNumber = XmlElementHelpers::stringValue($awardNumberElement);
            $awardUri = $awardNumberElement?->getAttribute('awardURI');

            $awardTitleElement = XmlElementHelpers::firstElementByKey($content, 'awardTitle');
            $awardTitle = XmlElementHelpers::stringValue($awardTitleElement);

            $fundingReferences[] = [
                'funderName' => $funderName,
                'funderIdentifier' => $funderIdentifier,
                'funderIdentifierType' => is_string($funderIdentifierType) ? $funderIdentifierType : null,
                'awardNumber' => $awardNumber,
                'awardUri' => is_string($awardUri) ? $awardUri : null,
                'awardTitle' => $awardTitle,
            ];
        }

        return $fundingReferences;
    }
}
