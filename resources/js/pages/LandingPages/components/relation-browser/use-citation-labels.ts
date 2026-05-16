import { useMemo } from 'react';

import { normalizeDoiKey } from '../../lib/resolveIdentifierUrl';
import type { CitationLabel } from './graph-types';

interface IdentifierInput {
    identifier: string;
    identifier_type: string;
    citation_label?: string | null;
}

/**
 * Extracts "Author, Year" from an APA-style citation string.
 * Example: "Doe, J., Smith, A. (2024). Title. Publisher." → "Doe, 2024"
 */
export function extractAuthorYear(citation: string): string {
    const yearMatch = citation.match(/\((\d{4})\)/);
    const year = yearMatch ? yearMatch[1] : '';

    const authorPart = citation.split('(')[0].trim();
    const firstAuthor = authorPart.split(',')[0].trim();

    if (firstAuthor && year) {
        return `${firstAuthor}, ${year}`;
    }
    if (firstAuthor) {
        return firstAuthor;
    }
    return citation.slice(0, 30);
}

/**
 * Shortens an identifier for display in a node label.
 * Example: "10.5880/GFZ.1.2.2024.001" → "10.5880/GFZ...001"
 */
function shortenIdentifier(identifier: string, maxLength = 20): string {
    if (identifier.length <= maxLength) {
        return identifier;
    }
    const start = identifier.slice(0, Math.floor(maxLength / 2));
    const end = identifier.slice(-Math.floor(maxLength / 3));
    return `${start}...${end}`;
}

export function useCitationLabels(
    identifiers: IdentifierInput[],
    citationTexts?: Map<string, string>,
): Map<string, CitationLabel> {
    return useMemo(() => {
        const labels = new Map<string, CitationLabel>();

        for (const item of identifiers) {
            const persistedCitation = item.citation_label?.trim();

            if (item.identifier_type === 'DOI') {
                const doi = normalizeDoiKey(item.identifier);

                if (!doi || labels.has(doi)) {
                    continue;
                }

                const provided = persistedCitation || citationTexts?.get(doi);

                if (provided) {
                    labels.set(doi, {
                        shortLabel: extractAuthorYear(provided),
                        fullCitation: provided,
                        loading: false,
                    });
                } else {
                    labels.set(doi, {
                        shortLabel: doi.length > 20 ? shortenIdentifier(doi) : doi,
                        fullCitation: doi,
                        loading: false,
                    });
                }
            } else {
                const key = `${item.identifier_type}:${item.identifier}`;
                if (!labels.has(key)) {
                    labels.set(key, {
                        shortLabel: persistedCitation ? extractAuthorYear(persistedCitation) : `${item.identifier_type}: ${shortenIdentifier(item.identifier)}`,
                        fullCitation: persistedCitation || item.identifier,
                        loading: false,
                    });
                }
            }
        }

        return labels;
    }, [identifiers, citationTexts]);
}

export function getCitationKey(identifierType: string, identifier: string): string {
    if (identifierType === 'DOI') {
        return normalizeDoiKey(identifier);
    }
    return `${identifierType}:${identifier}`;
}
