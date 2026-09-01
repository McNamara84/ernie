<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use DOMDocument;
use DOMElement;

/**
 * Produces a compact, lossless list of non-empty DIF leaf values.
 *
 * The typed extractor can evolve independently while this representation keeps
 * source values, attributes, namespaces, order, and sample provenance.
 */
final class IgsnLegacyDifSerializerService
{
    /**
     * @return array{
     *     version: int,
     *     schema_namespace: string|null,
     *     sample_count: int,
     *     fields: list<array{
     *         path: string,
     *         value: string,
     *         attributes: array<string, string>,
     *         namespace: string|null,
     *         sample_index: int|null
     *     }>
     * }|null
     */
    public function serialize(string $difXml): ?array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($difXml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || ! $dom->documentElement instanceof DOMElement) {
            return null;
        }

        $fields = [];
        $sampleCounter = 0;
        $this->walk($dom->documentElement, [], null, $sampleCounter, $fields);

        return [
            'version' => 1,
            'schema_namespace' => $this->nullable($dom->documentElement->namespaceURI),
            'sample_count' => $sampleCounter,
            'fields' => $fields,
        ];
    }

    /**
     * @param  list<string>  $parentPath
     * @param  list<array{path: string, value: string, attributes: array<string, string>, namespace: string|null, sample_index: int|null}>  $fields
     */
    private function walk(
        DOMElement $element,
        array $parentPath,
        ?int $sampleIndex,
        int &$sampleCounter,
        array &$fields,
    ): void {
        $localName = $element->localName ?? $element->nodeName;
        $path = [...$parentPath, $localName];
        if ($localName === 'sample') {
            $sampleIndex = $sampleCounter++;
        }

        $elementChildren = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elementChildren[] = $child;
            }
        }

        $attributes = $this->attributes($element);

        if ($elementChildren !== []) {
            foreach ($attributes as $name => $value) {
                if ($value === '') {
                    continue;
                }
                $fields[] = [
                    'path' => implode('/', [...$path, '@'.$name]),
                    'value' => $value,
                    'attributes' => [],
                    'namespace' => $this->nullable($element->namespaceURI),
                    'sample_index' => $sampleIndex,
                ];
            }
            foreach ($elementChildren as $child) {
                $this->walk($child, $path, $sampleIndex, $sampleCounter, $fields);
            }

            return;
        }

        $value = trim($element->textContent);
        if ($value === '' || strcasecmp($value, 'N/A') === 0) {
            return;
        }

        $fields[] = [
            'path' => implode('/', $path),
            'value' => $value,
            'attributes' => $attributes,
            'namespace' => $this->nullable($element->namespaceURI),
            'sample_index' => $sampleIndex,
        ];
    }

    private function nullable(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string, string> */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $localName = $attribute->localName ?? $attribute->nodeName;
            $name = $attribute->prefix !== null && $attribute->prefix !== ''
                ? $attribute->prefix.':'.$localName
                : $localName;
            $attributes[$name] = trim($attribute->value);
        }

        return $attributes;
    }
}
