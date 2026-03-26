import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Spinner } from '@/components/ui/spinner';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

interface RelatedIdentifier {
    id: number;
    identifier: string;
    identifier_type: string;
    relation_type: string;
    related_title?: string;
}

interface RelatedWorkSectionProps {
    relatedIdentifiers: RelatedIdentifier[];
}

interface Citation {
    citation: string;
    loading: boolean;
    error: boolean;
}

/**
 * Wandelt CamelCase in lesbaren Text mit Leerzeichen um
 * z.B. "IsDocumentedBy" -> "Is Documented By"
 */
function formatRelationType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

/**
 * Related Work Section
 *
 * Zeigt alle Related Identifiers gruppiert nach RelationType an.
 * Erste IsSupplementTo-Relation wird ausgeschlossen (die ist in Model Description).
 */
export function RelatedWorkSection({ relatedIdentifiers }: RelatedWorkSectionProps) {
    // Citation cache keyed by DOI string (deduplicated across relation types)
    const [citations, setCitations] = useState<Map<string, Citation>>(new Map());

    // Erste IsSupplementTo-Relation ausschließen
    const firstSupplementToIndex = relatedIdentifiers.findIndex((rel) => rel.relation_type === 'IsSupplementTo');

    const filteredRelations = relatedIdentifiers.filter((rel, index) => {
        // Erste IsSupplementTo ausschließen
        if (rel.relation_type === 'IsSupplementTo' && index === firstSupplementToIndex) {
            return false;
        }
        return true;
    });

    // Nach RelationType gruppieren
    const groupedByType = filteredRelations.reduce(
        (acc, rel) => {
            if (!acc[rel.relation_type]) {
                acc[rel.relation_type] = [];
            }
            acc[rel.relation_type].push(rel);
            return acc;
        },
        {} as Record<string, RelatedIdentifier[]>,
    );

    // Sortiere die Gruppen alphabetisch
    const sortedTypes = Object.keys(groupedByType).sort();

    useEffect(() => {
        const controller = new AbortController();

        // Deduplicate: only fetch each DOI once, normalize to bare DOI
        const doisToFetch = new Set<string>();
        filteredRelations.forEach((rel) => {
            if (rel.identifier_type === 'DOI') {
                const doi = normalizeDoiKey(rel.identifier);
                if (doi) {
                    doisToFetch.add(doi);
                }
            }
        });

        // Only fetch DOIs not already loaded or loading
        const newDois = new Set<string>();
        doisToFetch.forEach((doi) => {
            const existing = citations.get(doi);
            if (!existing || existing.error) {
                newDois.add(doi);
            }
        });

        if (newDois.size === 0) {
            return;
        }

        // Batch-initialize new DOIs as loading in a single state update
        setCitations((prev) => {
            const next = new Map(prev);
            newDois.forEach((doi) => {
                next.set(doi, { citation: '', loading: true, error: false });
            });
            return next;
        });

        // Fetch each new DOI citation, updating individually on resolve
        newDois.forEach((doi) => {
            fetch(`/api/datacite/citation/${encodeURIComponent(doi)}`, { signal: controller.signal })
                .then((response) => {
                    if (response.ok) {
                        return response.json();
                    }
                    throw new Error('Citation not found');
                })
                .then((data) => {
                    setCitations((prev) => new Map(prev).set(doi, { citation: data.citation, loading: false, error: false }));
                })
                .catch((err: unknown) => {
                    if (err instanceof Error && err.name === 'AbortError') {
                        return;
                    }
                    setCitations((prev) => new Map(prev).set(doi, { citation: '', loading: false, error: true }));
                });
        });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [relatedIdentifiers]);

    if (filteredRelations.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" data-testid="related-works-section">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">Related Work</h3>

            <div className="space-y-6" data-testid="related-works-list">
                {sortedTypes.map((relationType) => {
                    // Check if group has any renderable items
                    const items = groupedByType[relationType];
                    const hasRenderableItems = items.some((rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null);

                    if (!hasRenderableItems) {
                        return null;
                    }

                    return (
                        <div key={relationType}>
                            <h4 className="mb-3 text-sm font-semibold text-gray-700">{formatRelationType(relationType)}</h4>
                            <ul className="space-y-2">
                                {items.map((rel) => {
                                    const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

                                    if (!url) {
                                        return null;
                                    }

                                    // DOI: show citation (fetched async)
                                    if (rel.identifier_type === 'DOI') {
                                        const doiKey = normalizeDoiKey(rel.identifier);
                                        const citationData = citations.get(doiKey);
                                        const isLoading = !citationData || citationData.loading;

                                        return (
                                            <li key={rel.id}>
                                                {isLoading && (
                                                    <div className="flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-500">
                                                        <Spinner size="sm" className="mt-0.5 shrink-0" />
                                                        <span>Loading citation...</span>
                                                    </div>
                                                )}

                                                {!isLoading && citationData?.citation && (
                                                    <a
                                                        href={url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                                    >
                                                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                        <span className="flex-1">{citationData.citation}</span>
                                                    </a>
                                                )}

                                                {!isLoading && citationData?.error && (
                                                    <a
                                                        href={url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                                    >
                                                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                        <span className="flex-1">DOI: {doiKey}</span>
                                                    </a>
                                                )}
                                            </li>
                                        );
                                    }

                                    // Non-DOI: show identifier as link directly
                                    return (
                                        <li key={rel.id}>
                                            <a
                                                href={url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                            >
                                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                <span className="flex-1">{rel.identifier}</span>
                                            </a>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
