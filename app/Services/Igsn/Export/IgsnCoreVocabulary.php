<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/** Reads allowed values from the same pinned schemas used by the validator. */
final class IgsnCoreVocabulary
{
    /** @var array<string, list<string>> */
    private array $values = [];

    public function __construct(private readonly IgsnCoreSchemaResolver $schemas) {}

    public function allows(string $type, ?string $value): bool
    {
        return $value !== null && in_array($value, $this->values($type), true);
    }

    /** @return list<string> */
    public function values(string $type): array
    {
        if (isset($this->values[$type])) {
            return $this->values[$type];
        }
        if (! in_array($type, ['titleType', 'contributorType', 'dateType', 'relatedIdentifierType', 'relationType', 'funderIdentifierType', 'descriptionType', 'publisherIdentifierScheme'], true)) {
            throw new InvalidArgumentException('Unknown IGSN Core vocabulary.');
        }
        $document = new DOMDocument;
        $path = $type === 'publisherIdentifierScheme'
            ? IgsnCoreSchemaResolver::ENTRYPOINT
            : "DataCite4.7/include/datacite-{$type}-v4.xsd";
        $file = $this->schemas->publicFile($path);
        if ($file === null || ! $document->load($file['path'], LIBXML_NONET)) {
            throw new RuntimeException('The IGSN Core vocabulary is unavailable.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $expression = $type === 'publisherIdentifierScheme'
            ? '//xs:attribute[@name="publisherIdentifierScheme"]//xs:enumeration/@value'
            : '//xs:simpleType[@name="'.$type.'"]//xs:enumeration/@value';
        $values = [];
        foreach ($xpath->query($expression) ?: [] as $attribute) {
            $values[] = (string) $attribute->nodeValue;
        }

        return $this->values[$type] = $values;
    }
}
