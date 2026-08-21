<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Facades\Log;
use Saloon\XmlWrangler\XmlReader;

/**
 * Extracts subjects from the original DataCite XML embedded in API records.
 */
final readonly class OriginalDataCiteSubjectExtractionService
{
    public function __construct(
        private OriginalDataCiteXmlDecoder $xmlDecoder,
    ) {}

    /**
     * Replaces REST-derived subjects when valid original XML is available.
     *
     * @param  array<string, mixed>  $doiRecord
     * @return array<string, mixed>
     */
    public function preferOriginalSubjects(array $doiRecord, ?string $context = null): array
    {
        $isWrappedRecord = is_array($doiRecord['attributes'] ?? null);
        $attributes = $isWrappedRecord ? $doiRecord['attributes'] : $doiRecord;
        $subjects = $this->extractEncoded($attributes['xml'] ?? null, $context);

        if ($subjects === null) {
            return $doiRecord;
        }

        if ($isWrappedRecord) {
            $doiRecord['attributes']['subjects'] = $subjects;
        } else {
            $doiRecord['subjects'] = $subjects;
        }

        return $doiRecord;
    }

    /**
     * Returns null when no usable XML is available, and an empty list when a
     * valid XML document intentionally contains no subjects.
     *
     * @return list<array<string, string>>|null
     */
    public function extractEncoded(mixed $value, ?string $context = null): ?array
    {
        $xml = $this->xmlDecoder->decode($value);

        return $xml === null ? null : $this->extract($xml, $context);
    }

    /**
     * @return list<array<string, string>>|null
     */
    public function extract(string $xml, ?string $context = null): ?array
    {
        try {
            $elements = XmlReader::fromString($xml)
                ->xpathElement('//*[local-name()="resource"]/*[local-name()="subjects"]/*[local-name()="subject"]')
                ->get();
        } catch (\Throwable $exception) {
            Log::warning('Could not read XML subjects.', [
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $subjects = [];

        foreach ($elements as $element) {
            $value = trim((string) (XmlElementHelpers::stringValue($element) ?? ''));

            if ($value === '') {
                continue;
            }

            $subject = ['subject' => $value];

            $this->copyAttribute($subject, 'subjectScheme', $element->getAttribute('subjectScheme'));
            $this->copyAttribute($subject, 'schemeUri', $element->getAttribute('schemeURI'));
            $this->copyAttribute($subject, 'valueUri', $element->getAttribute('valueURI'));
            $this->copyAttribute($subject, 'classificationCode', $element->getAttribute('classificationCode'));
            $this->copyAttribute($subject, 'lang', $element->getAttribute('xml:lang'));

            $subjects[] = $subject;
        }

        return $subjects;
    }

    /**
     * @param  array<string, string>  $subject
     */
    private function copyAttribute(array &$subject, string $key, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $subject[$key] = trim($value);
    }
}
