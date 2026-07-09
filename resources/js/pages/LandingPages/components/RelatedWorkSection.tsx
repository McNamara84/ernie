import { ChevronDown, ChevronUp, ExternalLink, Network, Quote } from 'lucide-react';
import { lazy, Suspense, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { isRepositoryCurationRelatedIdentifier } from '@/lib/related-identifier-provenance';
import { cn } from '@/lib/utils';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem, LandingPageResource } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';
import { LandingPageCard } from './LandingPageCard';

const RelationBrowserModal = lazy(() => import('./RelationBrowserModal').then((m) => ({ default: m.RelationBrowserModal })));

interface RelatedWorkSectionProps {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    /** Inline relatedItem metadata (DataCite 4.7 Related Item Manager). Optional for backward-compatibility. */
    relatedItems?: LandingPageRelatedItem[];
    resource: LandingPageResource;
}

/**
 * Converts CamelCase to readable text with spaces.
 * e.g. "IsDocumentedBy" -> "Is Documented By"
 */
function formatRelationType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

function getRelatedIdentifierLabel(relatedIdentifier: LandingPageRelatedIdentifier): string {
    const citationLabel = relatedIdentifier.citation_label?.trim();

    if (citationLabel) {
        return citationLabel;
    }

    const relatedTitle = relatedIdentifier.related_title?.trim();

    if (relatedTitle) {
        return relatedTitle;
    }

    if (relatedIdentifier.identifier_type === 'DOI') {
        return `DOI: ${normalizeDoiKey(relatedIdentifier.identifier) ?? relatedIdentifier.identifier}`;
    }

    return relatedIdentifier.identifier;
}

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

function hasResolvableIdentifier(rel: LandingPageRelatedIdentifier): boolean {
    return resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null;
}

function getRelatedIdentifierLinkClassName(rel: LandingPageRelatedIdentifier): string {
    return cn(
        'group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50',
        isRepositoryCurationRelatedIdentifier(rel) &&
            'border-cyan-200 bg-cyan-50/70 text-cyan-950 hover:border-cyan-300 hover:bg-cyan-100/80 dark:border-cyan-800 dark:bg-cyan-950/20 dark:text-cyan-100 dark:hover:border-cyan-700 dark:hover:bg-cyan-950/40',
    );
}

/**
 * Related Work Section
 *
 * Displays all Related Identifiers grouped by RelationType.
 * The first IsSupplementTo relation is excluded (shown in Model Description).
 */
export function RelatedWorkSection({ relatedIdentifiers, relatedItems = [], resource }: RelatedWorkSectionProps) {
    const [browserOpen, setBrowserOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);

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

    const initialRelations = useMemo(() => filteredRelations.filter((rel) => !isRepositoryCurationRelatedIdentifier(rel)), [filteredRelations]);

    const repositoryCurationRelations = useMemo(() => filteredRelations.filter(isRepositoryCurationRelatedIdentifier), [filteredRelations]);

    const groupedByType = useMemo(() => groupRelatedIdentifiersByType(initialRelations), [initialRelations]);
    const groupedRepositoryCurationByType = useMemo(() => groupRelatedIdentifiersByType(repositoryCurationRelations), [repositoryCurationRelations]);

    // Sort groups alphabetically
    const sortedTypes = useMemo(() => Object.keys(groupedByType).sort(), [groupedByType]);
    const sortedRepositoryCurationTypes = useMemo(() => Object.keys(groupedRepositoryCurationByType).sort(), [groupedRepositoryCurationByType]);

    const hasRenderableRepositoryCurationRelations = useMemo(
        () => repositoryCurationRelations.some(hasResolvableIdentifier),
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

    // Only render if at least one relation has a resolvable URL
    const renderableRelations = useMemo(
        () => filteredRelations.filter((rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null),
        [filteredRelations],
    );

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
                    if (hasResolvableIdentifier(rel)) {
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
    if (renderableRelations.length === 0 && relatedItems.length === 0) {
        return null;
    }

    const shouldCollapse = renderableRelations.length > COLLAPSE_THRESHOLD;

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
                    // Skip groups where no item resolves to a valid URL
                    const items = groupedByType[relationType];
                    const hasRenderableItems = items.some(hasResolvableIdentifier);

                    if (!hasRenderableItems) {
                        return null;
                    }

                    // If all renderable items in this group are hidden, hide the group heading on mobile too
                    const allItemsHidden = items.every((rel) => !hasResolvableIdentifier(rel) || hiddenItemIds.has(rel.id));

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

                                    return (
                                        <li key={rel.id} className={isHiddenOnMobile ? 'collapsible-print-only hidden md:list-item' : ''}>
                                            <a
                                                href={url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className={getRelatedIdentifierLinkClassName(rel)}
                                            >
                                                <ExternalLink
                                                    className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300"
                                                    aria-hidden="true"
                                                />
                                                <span className="flex-1">{getRelatedIdentifierLabel(rel)}</span>
                                            </a>
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
                                const hasRenderableItems = items.some(hasResolvableIdentifier);

                                if (!hasRenderableItems) {
                                    return null;
                                }

                                const allItemsHidden = items.every((rel) => !hasResolvableIdentifier(rel) || hiddenItemIds.has(rel.id));

                                return (
                                    <div key={`repository-curation-${relationType}`} className={allItemsHidden ? 'hidden md:block' : ''}>
                                        <h4 className="mb-2 text-xs font-semibold text-cyan-800 uppercase dark:text-cyan-200">
                                            {formatRelationType(relationType)}
                                        </h4>
                                        <ul className="space-y-2">
                                            {items.map((rel) => {
                                                const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

                                                if (!url) {
                                                    return null;
                                                }

                                                const isHiddenOnMobile = hiddenItemIds.has(rel.id);

                                                return (
                                                    <li key={rel.id} className={isHiddenOnMobile ? 'collapsible-print-only hidden md:list-item' : ''}>
                                                        <a
                                                            href={url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className={getRelatedIdentifierLinkClassName(rel)}
                                                        >
                                                            <ExternalLink
                                                                className="mt-0.5 h-4 w-4 shrink-0 text-cyan-500 transition-colors group-hover:text-cyan-700 dark:text-cyan-300 dark:group-hover:text-cyan-100"
                                                                aria-hidden="true"
                                                            />
                                                            <span className="flex-1">{getRelatedIdentifierLabel(rel)}</span>
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
                )}

                {relatedItems.length > 0 && (
                    <div data-testid="related-items-list">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Citations
                            <Badge variant="secondary" className="gap-1 text-[10px] font-normal tracking-wide uppercase">
                                <Quote className="h-3 w-3" aria-hidden="true" />
                                Inline metadata
                            </Badge>
                        </h3>
                        <ul className="space-y-2">
                            {[...relatedItems]
                                .sort((a, b) => a.position - b.position)
                                .map((item) => {
                                    const mainTitle = item.titles.find((t) => t.title_type === 'MainTitle')?.title ?? item.titles[0]?.title ?? '';
                                    // Only resolve when a type is set: defaulting to 'DOI'
                                    // would generate bogus doi.org links for URL/Handle/etc.
                                    // identifiers if a legacy record ever lacked a type.
                                    const url =
                                        item.identifier && item.identifier_type ? resolveIdentifierUrl(item.identifier, item.identifier_type) : null;
                                    const authorList = item.creators
                                        .map((c) => c.family_name || c.name)
                                        .filter(Boolean)
                                        .slice(0, 3)
                                        .join(', ');
                                    const descriptor = [
                                        authorList,
                                        item.publication_year,
                                        item.publisher,
                                        item.volume ? `Vol. ${item.volume}` : null,
                                        item.issue ? `Issue ${item.issue}` : null,
                                        item.first_page && item.last_page ? `pp. ${item.first_page}-${item.last_page}` : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ');

                                    const content = (
                                        <div className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50">
                                            <Quote
                                                className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300"
                                                aria-hidden="true"
                                            />
                                            <div className="flex-1">
                                                <div className="font-medium">{mainTitle}</div>
                                                {descriptor && <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{descriptor}</div>}
                                            </div>
                                            {url && (
                                                <ExternalLink
                                                    className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300"
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </div>
                                    );

                                    return (
                                        <li key={item.id} data-testid={`related-item-${item.id}`}>
                                            {url ? (
                                                <a href={url} target="_blank" rel="noopener noreferrer">
                                                    {content}
                                                </a>
                                            ) : (
                                                content
                                            )}
                                        </li>
                                    );
                                })}
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
        </LandingPageCard>
    );
}
