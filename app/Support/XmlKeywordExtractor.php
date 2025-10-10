<?php

namespace App\Support;

use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

class XmlKeywordExtractor
{
    /**
     * GCMD vocabulary type prefixes in DataCite XML.
     * These prefixes appear before the actual hierarchical path in subject elements.
     */
    private const GCMD_PATH_PREFIXES = [
        'Science Keywords > ',
        'Platforms > ',
        'Instruments > ',
    ];

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
     * Parse GCMD hierarchical path from DataCite subject content.
     * 
     * Removes vocabulary type prefix (e.g., "Science Keywords > ") and splits
     * the remaining hierarchical path into an array.
     * 
     * Example input:  "Science Keywords > EARTH SCIENCE > ATMOSPHERE > CLOUDS"
     * Example output: ["EARTH SCIENCE", "ATMOSPHERE", "CLOUDS"]
     *
     * @param string $pathString The full path string from DataCite subject element
     * @return array<int, string> Array of path segments (trimmed)
     */
    public static function parseGcmdPath(string $pathString): array
    {
        // Remove GCMD vocabulary type prefix if present
        foreach (self::GCMD_PATH_PREFIXES as $prefix) {
            if (stripos($pathString, $prefix) === 0) {
                $pathString = substr($pathString, strlen($prefix));
                break;
            }
        }

        // Split by hierarchy separator and trim each segment
        return array_map('trim', explode(' > ', $pathString));
    }

    /**
     * Extract text content from an XML element
     */
    private function extractElementTextContent(Element $element): string
    {
        return $element->getContent();
    }
}
