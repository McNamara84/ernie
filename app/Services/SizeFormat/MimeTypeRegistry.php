<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class MimeTypeRegistry
{
    /** @var array<string, true>|null */
    private ?array $types = null;

    public function __construct(private readonly ?string $catalogPath = null) {}

    public function path(): string
    {
        return $this->catalogPath ?? resource_path('data/iana-media-types.json');
    }

    public function contains(string $value): bool
    {
        if ($this->types === null) {
            /** @var array{types: list<string>} $catalog */
            $catalog = json_decode((string) file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);
            $this->types = array_fill_keys($catalog['types'], true);
        }

        return isset($this->types[strtolower(trim($value))]);
    }

    /** @return array{source: string, registry_updated: string, source_sha256: string, types: list<string>} */
    public static function fromXml(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('The MIME registry must not contain a DTD or entities.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('Invalid IANA registry XML.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('i', 'http://www.iana.org/assignments');
            $roots = $xpath->query('/i:registry[@id="media-types"]');
            if ($roots === false || $roots->length !== 1) {
                throw new InvalidArgumentException('Expected the IANA media-types registry.');
            }
            $types = [];
            $names = $xpath->query('/i:registry/i:registry/i:record/i:name');
            if ($names === false) {
                throw new InvalidArgumentException('Could not read MIME registry records.');
            }
            foreach ($names as $name) {
                if (! $name instanceof DOMElement) {
                    continue;
                }
                $category = $name->parentNode?->parentNode?->attributes?->getNamedItem('id')?->nodeValue;
                // IANA includes deprecation annotations in some registered names.
                $subtype = preg_replace('/\s.*\z/s', '', trim($name->textContent)) ?? '';
                $type = strtolower($category.'/'.$subtype);
                if (preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\z/', $type) !== 1) {
                    throw new InvalidArgumentException('Invalid MIME registry entry: '.$type);
                }
                $types[$type] = true;
            }
            if ($types === []) {
                throw new InvalidArgumentException('The MIME registry is empty.');
            }
            $values = array_keys($types);
            sort($values, SORT_STRING);

            return [
                'source' => 'https://www.iana.org/assignments/media-types/media-types.xml',
                'registry_updated' => $xpath->evaluate('string(/i:registry/i:updated)'),
                'source_sha256' => hash('sha256', $xml),
                'types' => $values,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
