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
     * MSL Vocabulary scheme identifier in DataCite XML
     */
    private const MSL_VOCABULARY_SCHEME = 'EPOS MSL vocabulary';

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
            if (! $content || trim($content) === '') {
                continue;
            }

            $freeKeywords[] = trim($content);
        }

        return $freeKeywords;
    }

    /**
     * Extract MSL (Multi-Scale Laboratories) controlled vocabulary keywords from XML
     *
     * MSL keywords are subject elements with:
     * - subjectScheme="EPOS MSL vocabulary"
     * - schemeURI="https://epos-msl.uu.nl/voc"
     * - valueURI attribute containing the concept URI
     * - Content is hierarchical path (e.g., "Material > sedimentary rock > coal")
     *
     * @return array<int, array{id: string, text: string, path: string, language: string, scheme: string, schemeURI: string}>
     */
    public function extractMslKeywords(XmlReader $reader): array
    {
        $subjectElements = $reader
            ->xpathElement('//*[local-name()="subjects"]/*[local-name()="subject"]')
            ->get();

        $mslKeywords = [];

        foreach ($subjectElements as $element) {
            $scheme = $element->getAttribute('subjectScheme');
            $schemeUri = $element->getAttribute('schemeURI');
            $valueUri = $element->getAttribute('valueURI');
            $language = $element->getAttribute('xml:lang') ?? 'en';
            $content = $this->extractElementTextContent($element);

            // Only process MSL vocabulary keywords
            if ($scheme !== self::MSL_VOCABULARY_SCHEME) {
                continue;
            }

            // Skip if no valueURI (required for controlled vocabulary)
            if (! $valueUri || trim($valueUri) === '') {
                continue;
            }

            // Skip empty content
            if (! $content || trim($content) === '') {
                continue;
            }

            $mslKeywords[] = [
                'id' => trim($valueUri),
                'text' => $this->extractLastPathSegment(trim($content)),
                'path' => trim($content),
                'language' => $language,
                'scheme' => $scheme,
                'schemeURI' => $schemeUri ?? 'https://epos-msl.uu.nl/voc',
            ];
        }

        return $mslKeywords;
    }

    /**
     * Extract the last segment from a hierarchical path
     *
     * Example: "Material > sedimentary rock > coal" -> "coal"
     *
     * @param  string  $path  Hierarchical path with " > " separator
     * @return string Last segment of the path
     */
    private function extractLastPathSegment(string $path): string
    {
        $segments = array_map('trim', explode(' > ', $path));

        return end($segments) ?: $path;
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
     * @param  string  $pathString  The full path string from DataCite subject element
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
