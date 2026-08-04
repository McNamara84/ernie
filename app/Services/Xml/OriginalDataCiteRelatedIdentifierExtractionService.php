<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Services\RelatedIdentifierTypeResolverService;
use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Facades\Log;
use Saloon\XmlWrangler\XmlReader;

/**
 * Extracts related identifiers from DataCite XML documents.
 */
final readonly class OriginalDataCiteRelatedIdentifierExtractionService
{
    public function __construct(
        private RelatedIdentifierTypeResolverService $typeResolver,
    ) {}

    public function decode(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_contains($value, '<')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) && str_contains($decoded, '<') ? $decoded : null;
    }

    /**
     * @return list<array<string, string>>
     */
    public function extract(string $xml, ?string $context = null): array
    {
        try {
            return $this->extractFromReader(XmlReader::fromString($xml), $context);
        } catch (\Throwable $exception) {
            Log::warning('Could not parse XML related identifiers.', [
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public function extractFromReader(XmlReader $reader, ?string $context = null): array
    {
        try {
            $elements = $reader
                ->xpathElement('//*[local-name()="resource"]/*[local-name()="relatedIdentifiers"]/*[local-name()="relatedIdentifier"]')
                ->get();
        } catch (\Throwable $exception) {
            Log::warning('Could not read XML related identifiers.', [
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $relatedIdentifiers = [];

        foreach ($elements as $index => $element) {
            $identifier = trim((string) (XmlElementHelpers::stringValue($element) ?? ''));
            $identifierTypeRaw = $element->getAttribute('relatedIdentifierType');
            $relationTypeRaw = $element->getAttribute('relationType');
            $identifierType = $this->typeResolver->resolveIdentifierType($identifierTypeRaw);
            $relationType = $this->typeResolver->resolveRelationType($relationTypeRaw);

            if ($identifier === '' || $identifierType === null || $relationType === null) {
                Log::warning('Skipping invalid XML related identifier.', [
                    'context' => $context,
                    'index' => $index,
                    'identifier' => $identifier,
                    'relatedIdentifierType' => $identifierTypeRaw,
                    'relationType' => $relationTypeRaw,
                ]);

                continue;
            }

            $relatedIdentifier = [
                'relatedIdentifier' => $identifier,
                'relatedIdentifierType' => $identifierType,
                'relationType' => $relationType,
            ];

            $this->copyAttribute($relatedIdentifier, 'resourceTypeGeneral', $element->getAttribute('resourceTypeGeneral'));
            $this->copyAttribute($relatedIdentifier, 'relationTypeInformation', $element->getAttribute('relationTypeInformation'));
            $this->copyAttribute($relatedIdentifier, 'relatedMetadataScheme', $element->getAttribute('relatedMetadataScheme'));
            $this->copyAttribute($relatedIdentifier, 'schemeUri', $element->getAttribute('schemeURI'));
            $this->copyAttribute($relatedIdentifier, 'schemeType', $element->getAttribute('schemeType'));

            $relatedIdentifiers[] = $relatedIdentifier;
        }

        return $relatedIdentifiers;
    }

    /**
     * @param  array<string, string>  $relatedIdentifier
     */
    private function copyAttribute(array &$relatedIdentifier, string $key, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $relatedIdentifier[$key] = trim($value);
    }
}
