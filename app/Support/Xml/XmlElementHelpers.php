<?php

declare(strict_types=1);

namespace App\Support\Xml;

use Saloon\XmlWrangler\Data\Element;

/**
 * Pure helper functions for navigating and extracting values from
 * Saloon XmlWrangler trees.
 *
 * Extracted from UploadXmlController to enable reuse by per-section
 * parsers under App\Services\Xml\Sections.
 *
 * All methods are stateless and side-effect-free.
 */
final class XmlElementHelpers
{
    /**
     * Extract the first string value from an XmlReader::xpathValue() query result.
     *
     * Accepts the dynamic return type of XmlWrangler queries, which depending
     * on the query and document shape can be:
     *  - a `LazyQuery`-style object exposing `first()` (the common case),
     *  - an `array` of scalar / `Element` values (when the same element repeats
     *    or when callers pass through the already-collected result),
     *  - a bare scalar (`string|int|float`),
     *  - or `null` / anything else (no value).
     *
     * Returns the first scalar value coerced to a string, or `null`.
     */
    public static function firstStringFromQuery(mixed $query): ?string
    {
        if (is_object($query) && method_exists($query, 'first')) {
            $query = $query->first();
        }

        if (is_array($query)) {
            $query = self::firstScalarFromList($query);
        }

        if (is_string($query)) {
            return $query;
        }

        if (is_int($query) || is_float($query)) {
            return (string) $query;
        }

        return null;
    }

    /**
     * Walk a (possibly nested) array of XmlWrangler values and return the first
     * scalar leaf, mirroring how `LazyQuery::first()` peels through wrappers.
     *
     * @param  array<int|string, mixed>  $values
     */
    private static function firstScalarFromList(array $values): string|int|float|null
    {
        foreach ($values as $value) {
            if ($value instanceof Element) {
                $value = $value->getContent();
            }

            if (is_array($value)) {
                $nested = self::firstScalarFromList($value);
                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extract the first Element from an XmlReader::xpathElement() query result.
     */
    public static function firstElementFromQuery(mixed $query): ?Element
    {
        if (! is_object($query) || ! method_exists($query, 'first')) {
            return null;
        }

        $value = $query->first();

        return $value instanceof Element ? $value : null;
    }

    /**
     * Resolve the local element name from a possibly-prefixed XmlWrangler key.
     *
     * Handles two XmlWrangler peculiarities:
     *  - Attribute prefix ("@attr") and namespace prefixes ("ns:name")
     *  - Repeating siblings indexed as "name.1", "name.2", …
     */
    public static function localName(string $key): string
    {
        $trimmed = ltrim($key, '@');
        $colonPos = strrpos($trimmed, ':');
        $stripped = $colonPos === false ? $trimmed : substr($trimmed, $colonPos + 1);

        $dotPos = strrpos($stripped, '.');
        if ($dotPos !== false && ctype_digit(substr($stripped, $dotPos + 1))) {
            $stripped = substr($stripped, 0, $dotPos);
        }

        return $stripped;
    }

    /**
     * Check whether the given list contains at least one Element instance.
     *
     * @param  array<int|string, mixed>  $values
     */
    public static function containsElements(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof Element) {
                return true;
            }
        }

        return false;
    }

    /**
     * Direct child elements of $parent matching the given local name.
     *
     * XmlWrangler collapses repeating siblings of the same name into a single
     * Element whose content is an indexed list — this method flattens those.
     *
     * @return Element[]
     */
    public static function childElements(Element $parent, string $localName): array
    {
        $content = $parent->getContent();
        if (! is_array($content)) {
            return [];
        }

        $matches = [];
        foreach ($content as $key => $child) {
            if (self::localName((string) $key) !== $localName) {
                continue;
            }
            if ($child instanceof Element) {
                $inner = $child->getContent();
                if (is_array($inner) && array_is_list($inner) && self::containsElements($inner)) {
                    foreach ($inner as $nested) {
                        if ($nested instanceof Element) {
                            $matches[] = $nested;
                        }
                    }

                    continue;
                }
                $matches[] = $child;

                continue;
            }
            if (is_array($child)) {
                foreach ($child as $nested) {
                    if ($nested instanceof Element) {
                        $matches[] = $nested;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * First direct child element matching the given local name.
     */
    public static function firstChildElement(Element $parent, string $localName): ?Element
    {
        $matches = self::childElements($parent, $localName);

        return $matches[0] ?? null;
    }

    /**
     * Scalar string content of the first matching child element.
     */
    public static function scalarChild(Element $parent, string $localName): ?string
    {
        $child = self::firstChildElement($parent, $localName);
        if ($child === null) {
            return null;
        }
        $value = self::stringValue($child);

        return is_string($value) ? $value : null;
    }

    /**
     * Look up an Element by key inside an XmlWrangler-style content array
     * (returned by Element::getContent()).
     *
     * @param  array<string, mixed>  $content
     */
    public static function firstElementByKey(array $content, string $key): ?Element
    {
        $elements = self::allElementsByKey($content, $key);

        return array_first($elements);
    }

    /**
     * Look up all Elements by key inside an XmlWrangler-style content array.
     *
     * @param  array<string, mixed>  $content
     * @return Element[]
     */
    public static function allElementsByKey(array $content, string $key): array
    {
        if (! array_key_exists($key, $content)) {
            return [];
        }

        return self::normaliseToElementList($content[$key]);
    }

    /**
     * Recursively flatten a value (Element / array / scalar) into a list of Elements.
     *
     * @return Element[]
     */
    public static function normaliseToElementList(mixed $value): array
    {
        if ($value instanceof Element) {
            $content = $value->getContent();

            if (is_array($content)) {
                $elements = [];

                foreach ($content as $nested) {
                    array_push($elements, ...self::normaliseToElementList($nested));
                }

                return $elements ?: [$value];
            }

            return [$value];
        }

        if (is_array($value)) {
            $elements = [];

            foreach ($value as $nested) {
                array_push($elements, ...self::normaliseToElementList($nested));
            }

            return $elements;
        }

        return [];
    }

    /**
     * Trimmed string content of an Element, or null if empty / non-string.
     *
     * Recursively concatenates nested element string values separated by spaces.
     */
    public static function stringValue(?Element $element): ?string
    {
        if (! $element instanceof Element) {
            return null;
        }

        $content = $element->getContent();

        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $value) {
                $text = self::stringValue($value instanceof Element ? $value : null);

                if ($text !== null) {
                    $parts[] = $text;
                }
            }

            if ($parts !== []) {
                return trim(implode(' ', $parts));
            }
        }

        return null;
    }

    /**
     * Trimmed non-empty string, or null.
     */
    public static function stringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Strict integer parser (only digits, optional leading minus). Returns null otherwise.
     */
    public static function intOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^-?\d+$/', $trimmed) !== 1) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Split a "Family, Given" string into its components.
     *
     * @return array{givenName: string|null, familyName: string|null}
     */
    public static function splitCreatorName(?string $name): array
    {
        if (! is_string($name) || $name === '') {
            return ['givenName' => null, 'familyName' => null];
        }

        $parts = array_map('trim', explode(',', $name, 2));

        if (count($parts) === 2) {
            return [
                'familyName' => $parts[0] !== '' ? $parts[0] : null,
                'givenName' => $parts[1] !== '' ? $parts[1] : null,
            ];
        }

        return [
            'familyName' => $name,
            'givenName' => null,
        ];
    }
}
