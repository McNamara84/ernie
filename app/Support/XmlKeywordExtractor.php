<?php

namespace App\Support;

use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

class XmlKeywordExtractor
{
    /**
     * Extract free keywords from XML DataCite metadata
     * 
     * Free keywords are subject elements WITHOUT subjectScheme, schemeURI, or valueURI attributes.
     *
     * @return array<int, string>
     */
    public function extractFreeKeywords(XmlReader $reader): array
    {
        $subjectElements = $reader
            ->xpathElement('//*[local-name()="subjects"]/*[local-name()="subject"]')
            ->get();

        $freeKeywords = [];

        foreach ($subjectElements as $element) {
            $scheme = $element->getAttribute('subjectScheme');
            $schemeUri = $element->getAttribute('schemeURI');
            $valueUri = $element->getAttribute('valueURI');
            $content = $this->extractElementTextContent($element);

            // Only extract subjects that have NO schema attributes (indicating free keywords)
            if ($scheme || $schemeUri || $valueUri) {
                continue;
            }

            // Skip empty content
            if (!$content || trim($content) === '') {
                continue;
            }

            $freeKeywords[] = trim($content);
        }

        return $freeKeywords;
    }

    /**
     * Extract text content from an XML element
     */
    private function extractElementTextContent(Element $element): string
    {
        return $element->getContent();
    }
}
