<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\GcmdUriHelper;
use App\Support\Xml\XmlElementHelpers;
use App\Support\XmlKeywordExtractor;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses GCMD-style keywords (Science Keywords, Platforms, Instruments) and
 * other vocabulary keywords (e.g. International Chronostratigraphic Chart)
 * from `<subjects>/<subject>` elements of a DataCite XML document.
 *
 * Free, MSL, and GEMET keywords are handled separately by `XmlKeywordExtractor`.
 */
final readonly class GcmdKeywordSectionParser
{
    /**
     * @return array<int, array{
     *     uuid: string,
     *     id: string,
     *     text: string,
     *     path: string,
     *     scheme: string,
     *     schemeURI?: string,
     *     classificationCode?: string,
     * }>
     */
    public function parse(XmlReader $reader): array
    {
        $subjectElements = $reader
            ->xpathElement('//*[local-name()="subjects"]/*[local-name()="subject"]')
            ->get();

        $keywords = [];

        foreach ($subjectElements as $element) {
            $scheme = trim((string) $element->getAttribute('subjectScheme'));
            $valueUri = trim((string) $element->getAttribute('valueURI'));
            $classificationCode = trim((string) $element->getAttribute('classificationCode'));
            $content = XmlElementHelpers::stringValue($element);

            if ($scheme === '' || ! is_string($content) || $content === '') {
                continue;
            }

            $isGcmdKeyword = stripos($scheme, 'Science Keywords') !== false ||
                            stripos($scheme, 'Platforms') !== false ||
                            stripos($scheme, 'Instruments') !== false;

            if (! $isGcmdKeyword) {
                if ($valueUri !== '' || $classificationCode !== '') {
                    $schemeUri = trim((string) $element->getAttribute('schemeURI'));

                    $fullPath = trim($content);
                    $pathParts = array_map('trim', explode('>', $fullPath));
                    $leafText = end($pathParts) ?: $fullPath;

                    $keyword = [
                        'uuid' => '',
                        'id' => $valueUri !== '' ? $valueUri : $classificationCode,
                        'text' => $leafText,
                        'path' => $fullPath,
                        'scheme' => $scheme,
                    ];

                    if ($schemeUri !== '') {
                        $keyword['schemeURI'] = $schemeUri;
                    }

                    if ($classificationCode !== '') {
                        $keyword['classificationCode'] = $classificationCode;
                    }

                    $keywords[] = $keyword;
                }

                continue;
            }

            if ($valueUri === '') {
                continue;
            }

            $uuid = GcmdUriHelper::extractUuid($valueUri);

            if (! $uuid) {
                continue;
            }

            $id = GcmdUriHelper::buildConceptUri($uuid);

            $pathArray = XmlKeywordExtractor::parseGcmdPath($content);
            $pathString = implode(' > ', $pathArray);
            $text = array_last($pathArray) ?? $content;

            $normalizedScheme = $scheme;
            if (stripos($scheme, 'Science') !== false) {
                $normalizedScheme = 'Science Keywords';
            } elseif (stripos($scheme, 'Platform') !== false) {
                $normalizedScheme = 'Platforms';
            } elseif (stripos($scheme, 'Instrument') !== false) {
                $normalizedScheme = 'Instruments';
            }

            $keyword = [
                'uuid' => $uuid,
                'id' => $id,
                'text' => $text,
                'path' => $pathString,
                'scheme' => $normalizedScheme,
            ];

            if ($classificationCode !== '') {
                $keyword['classificationCode'] = $classificationCode;
            }

            $keywords[] = $keyword;
        }

        return $keywords;
    }
}
