<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\Xml\XmlElementHelpers;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<descriptions>/<description>` elements from a DataCite XML document.
 */
final readonly class DescriptionSectionParser
{
    /**
     * @return array<int, array{type: string, description: string, language: string|null}>
     */
    public function parse(XmlReader $reader, ?string $xmlContents = null): array
    {
        if (is_string($xmlContents) && trim($xmlContents) !== '') {
            $domDescriptions = self::parseFromDom($xmlContents);

            if ($domDescriptions !== null) {
                return $domDescriptions;
            }
        }

        return self::parseFromReader($reader);
    }

    /**
     * @return array<int, array{type: string, description: string, language: string|null}>|null
     */
    private static function parseFromDom(string $xmlContents): ?array
    {
        $document = new DOMDocument;
        $previousLibxmlSetting = libxml_use_internal_errors(true);

        $loaded = $document->loadXML($xmlContents, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);

        if ($loaded === false) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $descriptionNodes = $xpath->query('//*[local-name()="resource"]/*[local-name()="descriptions"]/*[local-name()="description"]');

        if ($descriptionNodes === false) {
            return [];
        }

        $descriptions = [];

        foreach ($descriptionNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $description = self::normalizeDescriptionText(self::descriptionTextFromDomNode($node));

            if ($description === '') {
                continue;
            }

            $descriptionType = $node->getAttribute('descriptionType');
            $descLang = $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');

            $descriptions[] = [
                'type' => $descriptionType !== '' ? $descriptionType : 'Other',
                'description' => $description,
                'language' => trim($descLang) !== '' ? trim($descLang) : null,
            ];
        }

        return $descriptions;
    }

    private static function descriptionTextFromDomNode(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $childNode) {
            if ($childNode instanceof DOMText) {
                $text .= $childNode->wholeText;

                continue;
            }

            if ($childNode instanceof DOMElement && $childNode->localName === 'br') {
                $text .= "\n";

                continue;
            }

            $text .= self::descriptionTextFromDomNode($childNode);
        }

        return $text;
    }

    /**
     * @return array<int, array{type: string, description: string, language: string|null}>
     */
    private static function parseFromReader(XmlReader $reader): array
    {
        $descriptionElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="descriptions"]/*[local-name()="description"]')
            ->get();

        $descriptions = [];

        foreach ($descriptionElements as $element) {
            $descriptionType = $element->getAttribute('descriptionType');
            $description = self::normalizeDescriptionText(
                self::descriptionTextFromContent($element->getContent())
            );

            if ($description === '') {
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

    private static function descriptionTextFromContent(mixed $content, string|int|null $key = null): string
    {
        if (self::isLineBreakKey($key)) {
            return self::lineBreakText($content);
        }

        if ($content instanceof Element) {
            return self::descriptionTextFromContent($content->getContent());
        }

        if (is_array($content)) {
            $text = '';

            foreach ($content as $childKey => $childValue) {
                $text .= self::descriptionTextFromContent($childValue, $childKey);
            }

            return $text;
        }

        if (is_scalar($content)) {
            return (string) $content;
        }

        return '';
    }

    private static function isLineBreakKey(string|int|null $key): bool
    {
        return is_string($key) && XmlElementHelpers::localName($key) === 'br';
    }

    private static function lineBreakText(mixed $content): string
    {
        $breakCount = 1;

        if ($content instanceof Element) {
            $content = $content->getContent();
        }

        if (is_array($content) && array_is_list($content) && $content !== []) {
            $breakCount = count($content);
        }

        return str_repeat("\n", $breakCount);
    }

    private static function normalizeDescriptionText(string $description): string
    {
        $description = str_replace(["\r\n", "\r"], "\n", $description);
        $description = preg_replace('/[ \t]+/u', ' ', $description) ?? $description;
        $description = preg_replace('/ *\n */u', "\n", $description) ?? $description;
        $description = preg_replace('/\n{3,}/u', "\n\n", $description) ?? $description;

        return trim($description);
    }
}
