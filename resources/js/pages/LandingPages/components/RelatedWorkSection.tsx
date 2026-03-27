import { ExternalLink, Network } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { LandingPageResource } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

const RelationBrowserModal = lazy(() => import('./RelationBrowserModal').then(m => ({ default: m.RelationBrowserModal })));

interface RelatedIdentifier {
    id: number;
    identifier: string;
    identifier_type: string;
    relation_type: string;
    related_title?: string;
}

interface RelatedWorkSectionProps {
    relatedIdentifiers: RelatedIdentifier[];
    resource: LandingPageResource;
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
export function RelatedWorkSection({ relatedIdentifiers, resource }: RelatedWorkSectionProps) {
    // Citation cache keyed by DOI string (deduplicated across relation types)
    const [citations, setCitations] = useState<Map<string, Citation>>(new Map());
    const [browserOpen, setBrowserOpen] = useState(false);

    // Erste IsSupplementTo-Relation ausschließen (memoized for referential stability)
    const filteredRelations = useMemo(() => {
        const firstSupplementToIndex = relatedIdentifiers.findIndex((rel) => rel.relation_type === 'IsSupplementTo');
        return relatedIdentifiers.filter((rel, index) => {
            if (rel.relation_type === 'IsSupplementTo' && index === firstSupplementToIndex) {
                return false;
            }
            return true;
        });
    }, [relatedIdentifiers]);

    // Nach RelationType gruppieren
    const groupedByType = useMemo(
        () =>
            filteredRelations.reduce(
                (acc, rel) => {
                    if (!acc[rel.relation_type]) {
                        acc[rel.relation_type] = [];
                    }
                    acc[rel.relation_type].push(rel);
                    return acc;
                },
                {} as Record<string, RelatedIdentifier[]>,
            ),
        [filteredRelations],
    );

    // Sortiere die Gruppen alphabetisch
    const sortedTypes = useMemo(() => Object.keys(groupedByType).sort(), [groupedByType]);

    useEffect(() => {
        const controller = new AbortController();

        // Deduplicate: only fetch DOIs that have a resolvable URL
        const doisToFetch = new Set<string>();
        filteredRelations.forEach((rel) => {
            if (rel.identifier_type === 'DOI') {
                const doi = normalizeDoiKey(rel.identifier);
                if (doi && resolveIdentifierUrl(rel.identifier, rel.identifier_type)) {
                    doisToFetch.add(doi);
                }
            }
        });

        // Fetch DOIs that are not yet successfully loaded.
        // Includes loading entries so that a prop-change after abort can retry.
        const newDois = new Set<string>();
        doisToFetch.forEach((doi) => {
            const existing = citations.get(doi);
            if (!existing || existing.loading || existing.error) {
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

    // Provide fully-loaded citation texts for the relation browser to avoid duplicate fetches
    const citationTexts = useMemo(() => {
        const map = new Map<string, string>();
        citations.forEach((v, k) => {
            if (!v.loading && !v.error && v.citation) {
                map.set(k, v.citation);
            }
        });
        return map;
    }, [citations]);

    // Only render if at least one relation has a resolvable URL
    const renderableRelations = useMemo(
        () => filteredRelations.filter(
            (rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null,
        ),
        [filteredRelations],
    );

    if (renderableRelations.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" data-testid="related-works-section">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-gray-900">Related Work</h3>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="group"
                    onClick={() => setBrowserOpen(true)}
                    aria-label="Open Relation Browser"
                    title="Open Relation Browser"
                    data-testid="relation-browser-button"
                >
                    <Network className="h-4 w-4 text-gray-500 transition-colors group-hover:text-[#0C2A63]" />
                </Button>
            </div>

            <div className="space-y-6" data-testid="related-works-list">
                {sortedTypes.map((relationType) => {
                    // Skip groups where no item resolves to a valid URL
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

            {browserOpen && (
                <Suspense fallback={null}>
                    <RelationBrowserModal
                        open={browserOpen}
                        onOpenChange={setBrowserOpen}
                        resource={resource}
                        relatedIdentifiers={filteredRelations}
                        citationTexts={citationTexts}
                    />
                </Suspense>
            )}
        </div>
    );
}
