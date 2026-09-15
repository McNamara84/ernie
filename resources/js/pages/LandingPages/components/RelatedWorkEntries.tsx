import { Check, Copy, ExternalLink, Quote } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { isRepositoryCurationRelatedIdentifier } from '@/lib/related-identifier-provenance';
import { cn } from '@/lib/utils';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

export function formatRelationType(type: string): string {
    return type
        .replace(/([A-Z])/g, ' $1')
        .replace(/\s+/g, ' ')
        .trim();
}

function getRelatedIdentifierLabel(relatedIdentifier: LandingPageRelatedIdentifier, useIgsnHandles: boolean): string {
    if (useIgsnHandles && relatedIdentifier.igsn) {
        return `IGSN: ${relatedIdentifier.igsn}`;
    }

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

export function getCopyableCitation(relatedIdentifier: LandingPageRelatedIdentifier): string | null {
    const citation = relatedIdentifier.citation_label?.trim();

    return citation || null;
}

export function hasDisplayableIdentifier(relatedIdentifier: LandingPageRelatedIdentifier): boolean {
    return (
        resolveIdentifierUrl(relatedIdentifier.identifier, relatedIdentifier.identifier_type) !== null ||
        (relatedIdentifier.identifier_type === 'IGSN' && relatedIdentifier.identifier.trim() !== '')
    );
}

function getRelatedIdentifierRowClassName(relatedIdentifier: LandingPageRelatedIdentifier, hasLink: boolean): string {
    return cn(
        'group flex items-stretch rounded-lg border border-gray-200 text-sm text-gray-700 transition-colors dark:border-gray-700 dark:text-gray-300',
        hasLink && 'hover:border-gray-300 hover:bg-gray-50 dark:hover:border-gray-600 dark:hover:bg-gray-700/50',
        isRepositoryCurationRelatedIdentifier(relatedIdentifier) &&
            'border-cyan-200 bg-cyan-50/70 text-cyan-950 dark:border-cyan-800 dark:bg-cyan-950/20 dark:text-cyan-100',
        hasLink &&
            isRepositoryCurationRelatedIdentifier(relatedIdentifier) &&
            'hover:border-cyan-300 hover:bg-cyan-100/80 dark:hover:border-cyan-700 dark:hover:bg-cyan-950/40',
    );
}

function RelatedIdentifierLabel({
    id,
    relatedIdentifier,
    useIgsnHandles,
}: {
    id?: string;
    relatedIdentifier: LandingPageRelatedIdentifier;
    useIgsnHandles: boolean;
}) {
    return (
        <span id={id} className="min-w-0 flex-1 [overflow-wrap:anywhere]">
            {getRelatedIdentifierLabel(relatedIdentifier, useIgsnHandles)}
        </span>
    );
}

interface RelatedIdentifierRowProps {
    relatedIdentifier: LandingPageRelatedIdentifier;
    url: string | null;
    useIgsnHandles: boolean;
    copied: boolean;
    onCopy: (relatedIdentifier: LandingPageRelatedIdentifier) => void;
}

export function RelatedIdentifierRow({ relatedIdentifier, url, useIgsnHandles, copied, onCopy }: RelatedIdentifierRowProps) {
    const citation = getCopyableCitation(relatedIdentifier);
    const labelId = `related-work-label-${relatedIdentifier.id}`;
    const isRepositoryCuration = isRepositoryCurationRelatedIdentifier(relatedIdentifier);

    return (
        <div className={getRelatedIdentifierRowClassName(relatedIdentifier, url !== null)} data-testid={`related-work-entry-${relatedIdentifier.id}`}>
            {url ? (
                <a href={url} target="_blank" rel="noopener noreferrer" className="flex min-w-0 flex-1 items-start gap-2 rounded-lg p-3 text-inherit">
                    <ExternalLink
                        className={cn(
                            'mt-0.5 h-4 w-4 shrink-0 transition-colors',
                            isRepositoryCuration
                                ? 'text-cyan-500 group-hover:text-cyan-700 dark:text-cyan-300 dark:group-hover:text-cyan-100'
                                : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300',
                        )}
                        aria-hidden="true"
                    />
                    <RelatedIdentifierLabel id={labelId} relatedIdentifier={relatedIdentifier} useIgsnHandles={useIgsnHandles} />
                </a>
            ) : (
                <div className="flex min-w-0 flex-1 items-start gap-2 p-3" data-testid={`unresolved-related-identifier-${relatedIdentifier.id}`}>
                    <RelatedIdentifierLabel id={labelId} relatedIdentifier={relatedIdentifier} useIgsnHandles={useIgsnHandles} />
                </div>
            )}

            {citation && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => onCopy(relatedIdentifier)}
                    className="min-h-11 min-w-11 shrink-0 self-start"
                    title={copied ? 'Copied!' : 'Copy citation'}
                    aria-label="Copy citation to clipboard"
                    aria-describedby={labelId}
                    data-print="hide"
                >
                    {copied ? (
                        <Check className="h-4 w-4 text-green-600 dark:text-green-400" aria-hidden="true" />
                    ) : (
                        <Copy className="h-4 w-4 text-gray-600 dark:text-gray-400" aria-hidden="true" />
                    )}
                </Button>
            )}
        </div>
    );
}

interface RelatedItemRowProps {
    item: LandingPageRelatedItem;
    showInlineMetadataBadge?: boolean;
}

export function RelatedItemRow({ item, showInlineMetadataBadge = false }: RelatedItemRowProps) {
    const mainTitle = item.titles.find((title) => title.title_type === 'MainTitle')?.title ?? item.titles[0]?.title ?? '';
    const identifier = item.identifier?.trim() ?? '';
    const identifierType = item.identifier_type?.trim() ?? '';
    // Only resolve when a type is set: defaulting to DOI would generate bogus
    // links for URL/Handle/etc. identifiers if a legacy record lacked a type.
    const url = identifier && identifierType ? resolveIdentifierUrl(identifier, identifierType) : null;
    const identifierStatus = identifier === '' ? 'Identifier not yet available' : identifierType === '' ? 'Identifier type not yet available' : null;
    const authorList = item.creators
        .map((creator) => creator.family_name || creator.name)
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
            <div className="min-w-0 flex-1 [overflow-wrap:anywhere]">
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 [overflow-wrap:anywhere]">
                    <span className="font-medium">{mainTitle}</span>
                    {showInlineMetadataBadge ? (
                        <Badge variant="secondary" className="gap-1 text-[10px] font-normal tracking-wide uppercase">
                            Inline metadata
                        </Badge>
                    ) : (
                        (item.relation_type || item.relation_type_slug) && (
                            <Badge variant="outline" className="text-[10px] font-normal">
                                {formatRelationType(item.relation_type || item.relation_type_slug || '')}
                            </Badge>
                        )
                    )}
                </div>
                {descriptor && <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{descriptor}</div>}
                {identifierStatus && <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{identifierStatus}</div>}
            </div>
            {url && (
                <ExternalLink
                    className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300"
                    aria-hidden="true"
                />
            )}
        </div>
    );

    return url ? (
        <a href={url} target="_blank" rel="noopener noreferrer">
            {content}
        </a>
    ) : (
        content
    );
}
