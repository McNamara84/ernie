import { ChevronDown, ChevronUp, Network, Quote } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { isRepositoryCurationRelatedIdentifier } from '@/lib/related-identifier-provenance';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem, LandingPageResource } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';
import { LandingPageCard } from './LandingPageCard';
import { formatRelationType, getCopyableCitation, hasDisplayableIdentifier, RelatedIdentifierRow, RelatedItemRow } from './RelatedWorkEntries';

const RelationBrowserModal = lazy(() => import('./RelationBrowserModal').then((m) => ({ default: m.RelationBrowserModal })));

interface RelatedWorkSectionProps {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    /** Inline relatedItem metadata (DataCite 4.7 Related Item Manager). Optional for backward-compatibility. */
    relatedItems?: LandingPageRelatedItem[];
    resource: LandingPageResource;
    /** Render configured IGSN DOI values as canonical handles. */
    useIgsnHandles?: boolean;
    /** Relation-type slugs hidden from every part of this module. */
    excludedRelationTypes?: readonly string[];
}

const normalizeTypeSlug = (value: string | null | undefined): string => value?.trim().toLowerCase() ?? '';

/** Number of renderable relations before collapsing on mobile */
const COLLAPSE_THRESHOLD = 9;

function groupRelatedIdentifiersByType(relations: LandingPageRelatedIdentifier[]): Record<string, LandingPageRelatedIdentifier[]> {
    return relations.reduce(
        (acc, rel) => {
            if (!acc[rel.relation_type]) {
                acc[rel.relation_type] = [];
            }
            acc[rel.relation_type].push(rel);
            return acc;
        },
        {} as Record<string, LandingPageRelatedIdentifier[]>,
    );
}

/**
 * Related Work Section
 *
 * Displays all Related Identifiers grouped by RelationType.
 * IsSupplementTo relations are excluded because the description section owns them.
 */
export function RelatedWorkSection({
    relatedIdentifiers,
    relatedItems = [],
    resource,
    useIgsnHandles = false,
    excludedRelationTypes = [],
}: RelatedWorkSectionProps) {
    const [browserOpen, setBrowserOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [copiedRelatedIdentifierId, setCopiedRelatedIdentifierId] = useState<number | null>(null);
    const [copyAnnouncementOperationId, setCopyAnnouncementOperationId] = useState<number | null>(null);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const copyOperationRef = useRef(0);

    useEffect(() => {
        return () => {
            copyOperationRef.current += 1;

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        };
    }, []);

    const clearCopiedState = () => {
        if (copyTimeoutRef.current) {
            clearTimeout(copyTimeoutRef.current);
            copyTimeoutRef.current = null;
        }

        setCopiedRelatedIdentifierId(null);
        setCopyAnnouncementOperationId(null);
    };

    const handleCopyCitation = async (relatedIdentifier: LandingPageRelatedIdentifier) => {
        const citation = getCopyableCitation(relatedIdentifier);

        if (!citation) {
            return;
        }

        const operationId = ++copyOperationRef.current;

        try {
            if (!navigator.clipboard?.writeText) {
                throw new Error('Clipboard API unavailable');
            }

            await navigator.clipboard.writeText(citation);

            if (operationId !== copyOperationRef.current) {
                return;
            }

            setCopiedRelatedIdentifierId(relatedIdentifier.id);
            setCopyAnnouncementOperationId(operationId);
            toast.success('Citation copied to clipboard');

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }

            copyTimeoutRef.current = setTimeout(() => {
                setCopiedRelatedIdentifierId(null);
                setCopyAnnouncementOperationId(null);
                copyTimeoutRef.current = null;
            }, 2000);
        } catch {
            if (operationId !== copyOperationRef.current) {
                return;
            }

            clearCopiedState();
            toast.error('Failed to copy citation');
        }
    };

    const excludedTypeSlugs = useMemo(() => new Set(excludedRelationTypes.map(normalizeTypeSlug).filter(Boolean)), [excludedRelationTypes]);
    const visibleRelatedIdentifiers = useMemo(
        () => relatedIdentifiers.filter((relation) => !excludedTypeSlugs.has(normalizeTypeSlug(relation.relation_type))),
        [excludedTypeSlugs, relatedIdentifiers],
    );
    const visibleRelatedItems = useMemo(
        () => relatedItems.filter((item) => !excludedTypeSlugs.has(normalizeTypeSlug(item.relation_type_slug))),
        [excludedTypeSlugs, relatedItems],
    );

    // Exclude all description-owned relations (memoized for referential stability).
    const filteredRelations = useMemo(
        () => visibleRelatedIdentifiers.filter((rel) => rel.relation_type !== 'IsSupplementTo'),
        [visibleRelatedIdentifiers],
    );

    const initialRelations = useMemo(() => filteredRelations.filter((rel) => !isRepositoryCurationRelatedIdentifier(rel)), [filteredRelations]);

    const repositoryCurationRelations = useMemo(() => filteredRelations.filter(isRepositoryCurationRelatedIdentifier), [filteredRelations]);

    const groupedByType = useMemo(() => groupRelatedIdentifiersByType(initialRelations), [initialRelations]);
    const groupedRepositoryCurationByType = useMemo(() => groupRelatedIdentifiersByType(repositoryCurationRelations), [repositoryCurationRelations]);

    // Sort groups alphabetically
    const sortedTypes = useMemo(() => Object.keys(groupedByType).sort(), [groupedByType]);
    const sortedRepositoryCurationTypes = useMemo(() => Object.keys(groupedRepositoryCurationByType).sort(), [groupedRepositoryCurationByType]);

    const hasRenderableRepositoryCurationRelations = useMemo(
        () => repositoryCurationRelations.some(hasDisplayableIdentifier),
        [repositoryCurationRelations],
    );

    // Provide persisted citation texts for the relation browser.
    const citationTexts = useMemo(() => {
        const map = new Map<string, string>();
        filteredRelations.forEach((relatedIdentifier) => {
            if (relatedIdentifier.identifier_type !== 'DOI') {
                return;
            }

            const citationLabel = relatedIdentifier.citation_label?.trim();
            const doi = normalizeDoiKey(relatedIdentifier.identifier);

            if (citationLabel && doi) {
                map.set(doi, citationLabel);
            }
        });
        return map;
    }, [filteredRelations]);

    const displayableRelations = useMemo(() => filteredRelations.filter(hasDisplayableIdentifier), [filteredRelations]);

    // IDs of items that should be hidden on mobile when collapsed (beyond threshold).
    // Computed in rendered order: initial metadata groups first, then repository-curated groups.
    const hiddenItemIds = useMemo(() => {
        if (expanded) {
            return new Set<number>();
        }

        const orderedIds: number[] = [];
        const appendRenderableIds = (relationTypes: string[], groups: Record<string, LandingPageRelatedIdentifier[]>) => {
            for (const relationType of relationTypes) {
                const items = groups[relationType];
                for (const rel of items) {
                    if (hasDisplayableIdentifier(rel)) {
                        orderedIds.push(rel.id);
                    }
                }
            }
        };

        appendRenderableIds(sortedTypes, groupedByType);
        appendRenderableIds(sortedRepositoryCurationTypes, groupedRepositoryCurationByType);

        if (orderedIds.length <= COLLAPSE_THRESHOLD) {
            return new Set<number>();
        }
        return new Set(orderedIds.slice(COLLAPSE_THRESHOLD));
    }, [sortedTypes, groupedByType, sortedRepositoryCurationTypes, groupedRepositoryCurationByType, expanded]);
    if (displayableRelations.length === 0 && visibleRelatedItems.length === 0) {
        return null;
    }

    const shouldCollapse = displayableRelations.length > COLLAPSE_THRESHOLD;

    return (
        <LandingPageCard aria-labelledby="heading-related-work" data-testid="related-works-section">
            <div className="mb-4 flex items-center justify-between">
                <h2 id="heading-related-work" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Related Work
                </h2>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="group min-h-11 min-w-11"
                    onClick={() => setBrowserOpen(true)}
                    aria-label="Open Relation Browser"
                    title="Open Relation Browser"
                    data-testid="relation-browser-button"
                    data-print="hide"
                >
                    <Network className="h-4 w-4 text-gray-500 transition-colors group-hover:text-gfz-primary dark:text-gray-400 dark:group-hover:text-blue-400" />
                </Button>
            </div>

            <div id="related-work-list" className="space-y-6" data-testid="related-works-list" aria-live="polite">
                {sortedTypes.map((relationType) => {
                    const items = groupedByType[relationType];
                    const hasRenderableItems = items.some(hasDisplayableIdentifier);

                    if (!hasRenderableItems) {
                        return null;
                    }

                    // If all renderable items in this group are hidden, hide the group heading on mobile too
                    const allItemsHidden = items.every((rel) => !hasDisplayableIdentifier(rel) || hiddenItemIds.has(rel.id));

                    return (
                        <div key={relationType} className={allItemsHidden ? 'hidden md:block' : ''}>
                            <h3 className="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">{formatRelationType(relationType)}</h3>
                            <ul className="space-y-2">
                                {items.map((rel) => {
                                    const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

                                    if (!hasDisplayableIdentifier(rel)) {
                                        return null;
                                    }

                                    const isHiddenOnMobile = hiddenItemIds.has(rel.id);

                                    return (
                                        <li key={rel.id} className={isHiddenOnMobile ? 'collapsible-print-only hidden md:list-item' : ''}>
                                            <RelatedIdentifierRow
                                                relatedIdentifier={rel}
                                                url={url}
                                                useIgsnHandles={useIgsnHandles}
                                                copied={copiedRelatedIdentifierId === rel.id}
                                                onCopy={handleCopyCitation}
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    );
                })}

                {hasRenderableRepositoryCurationRelations && (
                    <div data-testid="repository-curation-related-identifiers">
                        <h3 className="mb-3 text-sm font-semibold text-cyan-900 dark:text-cyan-100">Added by repository curation</h3>
                        <div className="space-y-4">
                            {sortedRepositoryCurationTypes.map((relationType) => {
                                const items = groupedRepositoryCurationByType[relationType];
                                const hasRenderableItems = items.some(hasDisplayableIdentifier);

                                if (!hasRenderableItems) {
                                    return null;
                                }

                                const allItemsHidden = items.every((rel) => !hasDisplayableIdentifier(rel) || hiddenItemIds.has(rel.id));

                                return (
                                    <div key={`repository-curation-${relationType}`} className={allItemsHidden ? 'hidden md:block' : ''}>
                                        <h4 className="mb-2 text-xs font-semibold text-cyan-800 uppercase dark:text-cyan-200">
                                            {formatRelationType(relationType)}
                                        </h4>
                                        <ul className="space-y-2">
                                            {items.map((rel) => {
                                                const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

                                                if (!hasDisplayableIdentifier(rel)) {
                                                    return null;
                                                }

                                                const isHiddenOnMobile = hiddenItemIds.has(rel.id);

                                                return (
                                                    <li key={rel.id} className={isHiddenOnMobile ? 'collapsible-print-only hidden md:list-item' : ''}>
                                                        <RelatedIdentifierRow
                                                            relatedIdentifier={rel}
                                                            url={url}
                                                            useIgsnHandles={useIgsnHandles}
                                                            copied={copiedRelatedIdentifierId === rel.id}
                                                            onCopy={handleCopyCitation}
                                                        />
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {visibleRelatedItems.length > 0 && (
                    <div data-testid="related-items-list">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Citations
                            <Badge variant="secondary" className="gap-1 text-[10px] font-normal tracking-wide uppercase">
                                <Quote className="h-3 w-3" aria-hidden="true" />
                                Inline metadata
                            </Badge>
                        </h3>
                        <ul className="space-y-2">
                            {[...visibleRelatedItems]
                                .sort((a, b) => a.position - b.position)
                                .map((item) => (
                                    <li key={item.id} data-testid={`related-item-${item.id}`}>
                                        <RelatedItemRow item={item} />
                                    </li>
                                ))}
                        </ul>
                    </div>
                )}
            </div>

            {/* Collapse/Expand toggle for mobile when >9 entries */}
            {shouldCollapse && (
                <div className="collapsible-toggle mt-4 md:hidden">
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
                                Show all ({displayableRelations.length})
                            </>
                        )}
                    </Button>
                </div>
            )}

            <span className="sr-only" aria-live="polite" role="status">
                {copyAnnouncementOperationId !== null ? <span key={copyAnnouncementOperationId}>Citation copied to clipboard</span> : null}
            </span>

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
        </LandingPageCard>
    );
}
