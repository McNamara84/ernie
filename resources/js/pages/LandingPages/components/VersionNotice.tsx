import { CircleAlert, ExternalLink, TriangleAlert } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

const VERSION_RELATIONS = new Set(['IsPreviousVersionOf', 'IsObsoletedBy']);

interface VersionNoticeProps {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    relatedItems?: LandingPageRelatedItem[];
}

interface VersionTarget {
    key: string;
    label: string;
    relationType: 'IsPreviousVersionOf' | 'IsObsoletedBy';
    url: string | null;
}

function normalizedIdentifierKey(identifier: string, identifierType: string): string {
    const normalizedType = identifierType.trim().toLowerCase();
    const normalized = normalizedType === 'doi' ? normalizeDoiKey(identifier).toLowerCase() : identifier.trim();

    return `${normalizedType}:${normalized}`;
}

function identifierLabel(identifier: string, identifierType: string): string {
    const normalized = identifierType === 'DOI' ? normalizeDoiKey(identifier) : identifier.trim();

    return `${identifierType}: ${normalized}`;
}

function mainTitle(item: LandingPageRelatedItem): string | null {
    return item.titles.find((title) => title.title_type === 'MainTitle')?.title.trim() || item.titles[0]?.title.trim() || null;
}

function collectVersionTargets(relatedIdentifiers: LandingPageRelatedIdentifier[], relatedItems: LandingPageRelatedItem[]): VersionTarget[] {
    const targets = new Map<string, VersionTarget>();

    for (const relation of relatedIdentifiers) {
        if (!VERSION_RELATIONS.has(relation.relation_type)) continue;

        const relationType = relation.relation_type as VersionTarget['relationType'];
        const key = normalizedIdentifierKey(relation.identifier, relation.identifier_type);
        targets.set(key, {
            key,
            label:
                relation.citation_label?.trim() || relation.related_title?.trim() || identifierLabel(relation.identifier, relation.identifier_type),
            relationType,
            url: resolveIdentifierUrl(relation.identifier, relation.identifier_type),
        });
    }

    for (const item of relatedItems) {
        if (!item.relation_type_slug || !VERSION_RELATIONS.has(item.relation_type_slug)) continue;

        const relationType = item.relation_type_slug as VersionTarget['relationType'];
        const hasIdentifier = Boolean(item.identifier?.trim() && item.identifier_type?.trim());
        const key = hasIdentifier ? normalizedIdentifierKey(item.identifier as string, item.identifier_type as string) : `related-item:${item.id}`;
        const existing = targets.get(key);
        const title = mainTitle(item);

        targets.set(key, {
            key,
            label:
                title ||
                existing?.label ||
                (hasIdentifier ? identifierLabel(item.identifier as string, item.identifier_type as string) : 'Related resource'),
            relationType: existing?.relationType === 'IsObsoletedBy' ? existing.relationType : relationType,
            url: existing?.url || (hasIdentifier ? resolveIdentifierUrl(item.identifier as string, item.identifier_type as string) : null),
        });
    }

    return Array.from(targets.values());
}

/**
 * Prominent navigation notice for resources that have a newer or superseding
 * version. It is intentionally independent from Related Work visibility
 * settings so version safety information cannot be hidden accidentally.
 */
export function VersionNotice({ relatedIdentifiers, relatedItems = [] }: VersionNoticeProps) {
    const targets = collectVersionTargets(relatedIdentifiers, relatedItems);

    if (targets.length === 0) return null;

    const isObsoleted = targets.some((target) => target.relationType === 'IsObsoletedBy');
    const headingId = 'resource-version-notice-heading';
    const Icon = isObsoleted ? TriangleAlert : CircleAlert;

    return (
        <aside
            aria-labelledby={headingId}
            data-testid="version-notice"
            data-severity={isObsoleted ? 'warning' : 'info'}
            className={cn(
                'rounded-lg border px-4 py-4 shadow-sm print:break-inside-avoid',
                isObsoleted
                    ? 'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100'
                    : 'border-blue-300 bg-blue-50 text-blue-950 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-100',
            )}
        >
            <div className="flex items-start gap-3">
                <Icon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                <div className="min-w-0">
                    <h2 id={headingId} className="font-semibold">
                        {isObsoleted ? 'This resource has been superseded.' : 'There is a newer version of this resource.'}
                    </h2>
                    <ul className="mt-2 space-y-1 text-sm">
                        {targets.map((target) => (
                            <li key={target.key} className="flex min-w-0 items-start gap-1.5">
                                <span className="shrink-0 font-medium">
                                    {target.relationType === 'IsObsoletedBy' ? 'Superseding resource:' : 'Newer version:'}
                                </span>
                                {target.url ? (
                                    <a
                                        href={target.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-w-0 items-start gap-1 underline decoration-dotted underline-offset-2 hover:no-underline"
                                    >
                                        <span className="[overflow-wrap:anywhere]">{target.label}</span>
                                        <ExternalLink className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    </a>
                                ) : (
                                    <span className="[overflow-wrap:anywhere]">{target.label}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </aside>
    );
}
