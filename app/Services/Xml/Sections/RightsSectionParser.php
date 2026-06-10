<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<rightsList>/<rights>` for the editor import payload.
 *
 * The old editor flow only needed `rightsIdentifier` values for the license
 * multi-select. SPDX enrichment also needs the literal imported statement, so
 * this parser now exposes both shapes: `parse()` keeps the old identifier list
 * and `parseRawRights()` keeps the full DataCite rights node.
 */
final readonly class RightsSectionParser
{
    /**
     * @return array<int, string>
     */
    public function parse(XmlReader $reader): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $rights): ?string => $this->filled($rights['rightsIdentifier'] ?? null),
                $this->parseRawRights($reader),
            ),
        ));
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function parseRawRights(XmlReader $reader): array
    {
        $rightsElements = $reader
            ->xpathElement('//*[local-name()="rightsList"]/*[local-name()="rights"]')
            ->get();

        $rightsStatements = [];

        foreach ($rightsElements as $element) {
            $statement = [
                'rights' => $this->filled($element->getContent()),
                'rightsUri' => $this->filled($element->getAttribute('rightsURI')),
                'rightsIdentifier' => $this->filled($element->getAttribute('rightsIdentifier')),
                'rightsIdentifierScheme' => $this->filled($element->getAttribute('rightsIdentifierScheme')),
                'schemeUri' => $this->filled($element->getAttribute('schemeURI')),
                'lang' => $this->filled($element->getAttribute('xml:lang')),
                'source' => 'xml-upload',
            ];

            $statement = array_filter(
                $statement,
                fn (?string $value): bool => $value !== null,
            );

            if ($statement !== []) {
                $rightsStatements[] = $statement;
            }
        }

        return $rightsStatements;
    }

    private function filled(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
