import { ChevronDown, ChevronUp, ExternalLink, Network } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { LandingPageResource } from '@/types/landing-page';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';
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
 * Converts CamelCase to readable text with spaces.
 * e.g. "IsDocumentedBy" -> "Is Documented By"
 */
function formatRelationType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

/** Number of renderable relations before collapsing on mobile */
const COLLAPSE_THRESHOLD = 9;

/**
 * Related Work Section
 *
 * Displays all Related Identifiers grouped by RelationType.
 * The first IsSupplementTo relation is excluded (shown in Model Description).
 */
export function RelatedWorkSection({ relatedIdentifiers, resource }: RelatedWorkSectionProps) {
    // Citation cache keyed by DOI string (deduplicated across relation types)
    const [citations, setCitations] = useState<Map<string, Citation>>(new Map());
    const [browserOpen, setBrowserOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const { ref, isVisible } = useFadeInOnScroll();

    // Exclude the first IsSupplementTo relation (memoized for referential stability)
    const filteredRelations = useMemo(() => {
        const firstSupplementToIndex = relatedIdentifiers.findIndex((rel) => rel.relation_type === 'IsSupplementTo');
        return relatedIdentifiers.filter((rel, index) => {
            if (rel.relation_type === 'IsSupplementTo' && index === firstSupplementToIndex) {
                return false;
            }
            return true;
        });
    }, [relatedIdentifiers]);

    // Group by RelationType
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

    // Sort groups alphabetically
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

    // IDs of items that should be hidden on mobile when collapsed (beyond threshold).
    // Computed in the same order items are rendered: alphabetically by relation type group,
    // then by item order within each group — so the first N visible items match what the user sees.
    const hiddenItemIds = useMemo(() => {
        if (expanded) {
            return new Set<number>();
        }

        // Build a flat list of renderable IDs in rendered order (grouped + sorted)
        const orderedIds: number[] = [];
        for (const relationType of sortedTypes) {
            const items = groupedByType[relationType];
            for (const rel of items) {
                if (resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null) {
                    orderedIds.push(rel.id);
                }
            }
        }

        if (orderedIds.length <= COLLAPSE_THRESHOLD) {
            return new Set<number>();
        }
        return new Set(orderedIds.slice(COLLAPSE_THRESHOLD));
    }, [sortedTypes, groupedByType, expanded]);

    if (renderableRelations.length === 0) {
        return null;
    }

    const shouldCollapse = renderableRelations.length > COLLAPSE_THRESHOLD;

    return (
        <section
            ref={ref}
            aria-labelledby="heading-related-work"
            className={`rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all duration-200 ease-in-out hover:shadow-md dark:border-gray-700 dark:bg-gray-800 ${isVisible ? 'visible opacity-100' : 'invisible opacity-0'}`}
            data-testid="related-works-section"
        >
            <div className="mb-4 flex items-center justify-between">
                <h2 id="heading-related-work" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Related Work</h2>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="group min-h-11 min-w-11"
                    onClick={() => setBrowserOpen(true)}
                    aria-label="Open Relation Browser"
                    title="Open Relation Browser"
                    data-testid="relation-browser-button"
                >
                    <Network className="h-4 w-4 text-gray-500 transition-colors group-hover:text-gfz-primary dark:text-gray-400 dark:group-hover:text-blue-400" />
                </Button>
            </div>

            <div
                id="related-work-list"
                className="space-y-6"
                data-testid="related-works-list"
                aria-live="polite"
            >
                {sortedTypes.map((relationType) => {
                    // Skip groups where no item resolves to a valid URL
                    const items = groupedByType[relationType];
                    const hasRenderableItems = items.some((rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null);

                    if (!hasRenderableItems) {
                        return null;
                    }

                    // If all renderable items in this group are hidden, hide the group heading on mobile too
                    const allItemsHidden = items.every(
                        (rel) => !resolveIdentifierUrl(rel.identifier, rel.identifier_type) || hiddenItemIds.has(rel.id),
                    );

                    return (
                        <div key={relationType} className={allItemsHidden ? 'hidden md:block' : ''}>
                            <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">{formatRelationType(relationType)}</h3>
                            <ul className="space-y-2">
                                {items.map((rel) => {
                                    const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

                                    if (!url) {
                                        return null;
                                    }

                                    const isHiddenOnMobile = hiddenItemIds.has(rel.id);

                                    // DOI: show citation (fetched async)
                                    if (rel.identifier_type === 'DOI') {
                                        const doiKey = normalizeDoiKey(rel.identifier);
                                        const citationData = citations.get(doiKey);
                                        const isLoading = !citationData || citationData.loading;

                                        return (
                                            <li key={rel.id} className={isHiddenOnMobile ? 'hidden md:list-item' : ''}>
                                                {isLoading && (
                                                    <div className="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700" aria-busy="true">
                                                        <Skeleton className="h-4 w-3/4" />
                                                        <Skeleton className="h-4 w-1/2" />
                                                    </div>
                                                )}

                                                {!isLoading && citationData?.citation && (
                                                    <a
                                                        href={url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                                                    >
                                                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
                                                        <span className="flex-1">{citationData.citation}</span>
                                                    </a>
                                                )}

                                                {!isLoading && citationData?.error && (
                                                    <a
                                                        href={url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                                                    >
                                                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
                                                        <span className="flex-1">DOI: {doiKey}</span>
                                                    </a>
                                                )}
                                            </li>
                                        );
                                    }

                                    // Non-DOI: show identifier as link directly
                                    return (
                                        <li key={rel.id} className={isHiddenOnMobile ? 'hidden md:list-item' : ''}>
                                            <a
                                                href={url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                                            >
                                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
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

            {/* Collapse/Expand toggle for mobile when >9 entries */}
            {shouldCollapse && (
                <div className="mt-4 md:hidden">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setExpanded(!expanded)}
                        aria-expanded={expanded}
                        aria-controls="related-work-list"
                        className="w-full gap-2 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        {expanded ? (
                            <>
                                <ChevronUp className="h-4 w-4" aria-hidden="true" />
                                Show less
                            </>
                        ) : (
                            <>
                                <ChevronDown className="h-4 w-4" aria-hidden="true" />
                                Show all ({renderableRelations.length})
                            </>
                        )}
                    </Button>
                </div>
            )}

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
        </section>
    );
}
