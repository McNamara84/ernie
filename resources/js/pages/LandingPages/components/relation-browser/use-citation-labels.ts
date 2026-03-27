import { useEffect, useRef, useState } from 'react';

import { normalizeDoiKey } from '../../lib/resolveIdentifierUrl';

import type { CitationLabel } from './graph-types';

interface IdentifierInput {
    identifier: string;
    identifier_type: string;
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
    const [labels, setLabels] = useState<Map<string, CitationLabel>>(new Map());
    const controllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;

        const newLabels = new Map<string, CitationLabel>();

        const doisToFetch: string[] = [];

        for (const item of identifiers) {
            if (item.identifier_type === 'DOI') {
                const doi = normalizeDoiKey(item.identifier);
                if (doi && !newLabels.has(doi)) {
                    const provided = citationTexts?.get(doi);
                    if (provided) {
                        newLabels.set(doi, {
                            shortLabel: extractAuthorYear(provided),
                            fullCitation: provided,
                            loading: false,
                        });
                    } else {
                        newLabels.set(doi, {
                            shortLabel: doi.length > 20 ? shortenIdentifier(doi) : doi,
                            fullCitation: doi,
                            loading: true,
                        });
                        doisToFetch.push(doi);
                    }
                }
            } else {
                const key = `${item.identifier_type}:${item.identifier}`;
                if (!newLabels.has(key)) {
                    newLabels.set(key, {
                        shortLabel: `${item.identifier_type}: ${shortenIdentifier(item.identifier)}`,
                        fullCitation: item.identifier,
                        loading: false,
                    });
                }
            }
        }

        setLabels(new Map(newLabels));

        if (doisToFetch.length === 0) {
            return () => controller.abort();
        }

        for (const doi of doisToFetch) {
            fetch(`/api/datacite/citation/${encodeURIComponent(doi)}`, {
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Citation not found');
                    }
                    return response.json();
                })
                .then((data) => {
                    const shortLabel = extractAuthorYear(data.citation);
                    setLabels((prev) => {
                        const next = new Map(prev);
                        next.set(doi, {
                            shortLabel,
                            fullCitation: data.citation,
                            loading: false,
                        });
                        return next;
                    });
                })
                .catch((err: unknown) => {
                    if (err instanceof Error && err.name === 'AbortError') {
                        return;
                    }
                    setLabels((prev) => {
                        const next = new Map(prev);
                        const existing = next.get(doi);
                        next.set(doi, {
                            shortLabel: existing?.shortLabel ?? doi,
                            fullCitation: doi,
                            loading: false,
                        });
                        return next;
                    });
                });
        }

        return () => controller.abort();
    }, [identifiers, citationTexts]);

    return labels;
}

export function getCitationKey(identifierType: string, identifier: string): string {
    if (identifierType === 'DOI') {
        return normalizeDoiKey(identifier);
    }
    return `${identifierType}:${identifier}`;
}
