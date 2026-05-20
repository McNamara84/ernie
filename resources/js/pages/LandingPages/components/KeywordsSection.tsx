import { Clock, ExternalLink, FlaskConical, Globe, Leaf, type LucideIcon, Microscope, Satellite } from 'lucide-react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    getSchemeLabel,
    SCHEME_GCMD_INSTRUMENTS,
    SCHEME_GCMD_PLATFORMS,
    SCHEME_GCMD_SCIENCE,
    SCHEME_GEMET,
    SCHEME_ICS_CHRONOSTRAT,
    SCHEME_MSL,
} from '@/lib/keyword-schemes';
import { buildPortalFilterUrl } from '@/lib/portal-filter-url';
import type { LandingPageSubject } from '@/types/landing-page';
import type { PortalFilters } from '@/types/portal';

import { CollapsibleList } from './CollapsibleList';

/** Single source of truth: ordered thesaurus definitions with badge styling and icons */
const THESAURUS_DEFINITIONS: { scheme: string; icon: LucideIcon; bgClass: string; textClass: string }[] = [
    { scheme: SCHEME_GCMD_SCIENCE, icon: Globe, bgClass: 'bg-blue-600 dark:bg-blue-500', textClass: 'text-white' },
    { scheme: SCHEME_GCMD_PLATFORMS, icon: Satellite, bgClass: 'bg-emerald-700 dark:bg-emerald-600', textClass: 'text-white' },
    { scheme: SCHEME_GCMD_INSTRUMENTS, icon: Microscope, bgClass: 'bg-amber-700 dark:bg-amber-600', textClass: 'text-white' },
    { scheme: SCHEME_MSL, icon: FlaskConical, bgClass: 'bg-purple-600 dark:bg-purple-500', textClass: 'text-white' },
    { scheme: SCHEME_GEMET, icon: Leaf, bgClass: 'bg-rose-600 dark:bg-rose-500', textClass: 'text-white' },
    { scheme: SCHEME_ICS_CHRONOSTRAT, icon: Clock, bgClass: 'bg-teal-700 dark:bg-teal-600', textClass: 'text-white' },
];

const THESAURUS_SCHEMES = new Set(THESAURUS_DEFINITIONS.map((d) => d.scheme));
const SCHEME_CONFIG = Object.fromEntries(THESAURUS_DEFINITIONS.map((d) => [d.scheme, { bg: d.bgClass, text: d.textClass, icon: d.icon }]));

const FREE_KEYWORD_STYLE = { bg: 'bg-gfz-primary', text: 'text-gfz-primary-foreground' };
const EMPTY_PORTAL_FILTERS: PortalFilters = {
    query: null,
    type: [],
    keywords: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};

function getFullPath(subject: LandingPageSubject): string | null {
    if (!subject.subject_scheme || subject.subject_scheme === '') {
        return null;
    }

    const breadcrumbPath = subject.breadcrumb_path?.trim();

    return breadcrumbPath ? breadcrumbPath : null;
}

function getDisplayLabel(subject: LandingPageSubject): string {
    const fullPath = getFullPath(subject);
    if (!fullPath) {
        return subject.subject;
    }

    const segments = fullPath.split(' > ').map((segment) => segment.trim()).filter((segment) => segment !== '');

    if (segments.length <= 3) {
        return segments.join(' > ');
    }

    const topLevel = segments[0];
    const broader = segments.at(-2) ?? segments[segments.length - 1];
    const narrow = segments.at(-1) ?? subject.subject;

    return `${topLevel} > ... > ${broader} > ${narrow}`;
}

function getPortalUrl(subject: LandingPageSubject): string | null {
    if (!subject.subject_scheme || subject.subject_scheme === '') {
        return buildPortalFilterUrl({
            ...EMPTY_PORTAL_FILTERS,
            freeKeywords: [subject.subject],
        });
    }

    const valueUri = subject.value_uri?.trim();
    if (!valueUri) {
        return null;
    }

    return buildPortalFilterUrl({
        ...EMPTY_PORTAL_FILTERS,
        thesaurusKeywords: [valueUri],
    });
}

/**
 * Renders a keyword badge that links to the portal with the keyword as filter.
 */
function KeywordBadge({ subject, style, icon: Icon }: { subject: LandingPageSubject; style?: { bg: string; text: string }; icon?: LucideIcon }) {
    const portalUrl = getPortalUrl(subject);
    const config = SCHEME_CONFIG[subject.subject_scheme ?? ''];
    const { bg, text } = style ?? config ?? FREE_KEYWORD_STYLE;
    const BadgeIcon = Icon ?? config?.icon;
    const schemeLabel = getSchemeLabel(subject.subject_scheme ?? null);
    const fullPath = getFullPath(subject);
    const displayLabel = getDisplayLabel(subject);
    const accessibleLabel = `${fullPath ?? displayLabel} (${schemeLabel})`;
    const badgeClass = `inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium ${portalUrl ? 'transition-opacity hover:opacity-80' : 'cursor-default'} ${bg} ${text}`;

    const badgeContent = (
        <>
            {BadgeIcon && <BadgeIcon className="h-3 w-3" aria-hidden="true" />}
            {displayLabel}
            {portalUrl && <ExternalLink className="h-3 w-3 opacity-70" aria-hidden="true" />}
        </>
    );

    const badge = portalUrl ? (
        <a
            href={portalUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={badgeClass}
            title={fullPath ?? `Search for "${subject.subject}" in the portal`}
            aria-label={accessibleLabel}
        >
            {badgeContent}
        </a>
    ) : (
        <span
            className={badgeClass}
            title={fullPath ?? displayLabel}
            aria-label={accessibleLabel}
        >
            {badgeContent}
        </span>
    );

    if (!fullPath) {
        return badge;
    }

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>{badge}</TooltipTrigger>
                <TooltipContent className="max-w-sm text-center">{fullPath}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface KeywordsSectionProps {
    subjects: LandingPageSubject[];
}

/**
 * Renders keywords grouped by thesaurus scheme (ordered) and free keywords.
 */
export function KeywordsSection({ subjects }: KeywordsSectionProps) {
    // Single-pass grouping: bucket subjects by scheme, then emit in defined order
    const schemeGroups = new Map<string, LandingPageSubject[]>();
    const freeKeywords: LandingPageSubject[] = [];
    for (const s of subjects) {
        if (!s.subject_scheme || s.subject_scheme === '') {
            freeKeywords.push(s);
        } else if (THESAURUS_SCHEMES.has(s.subject_scheme)) {
            const group = schemeGroups.get(s.subject_scheme);
            if (group) {
                group.push(s);
            } else {
                schemeGroups.set(s.subject_scheme, [s]);
            }
        }
    }
    const thesauriKeywords = THESAURUS_DEFINITIONS.flatMap((d) => schemeGroups.get(d.scheme) ?? []);
    const hasAnyKeywords = thesauriKeywords.length > 0 || freeKeywords.length > 0;

    // Build a flat ordered array: thesauri keywords first, then free keywords.
    // Each entry carries a `group` tag so the render function can style them.
    type TaggedKeyword = { subject: LandingPageSubject; group: 'thesaurus' | 'free' };
    const allKeywords: TaggedKeyword[] = [
        ...thesauriKeywords.map((s) => ({ subject: s, group: 'thesaurus' as const })),
        ...freeKeywords.map((s) => ({ subject: s, group: 'free' as const })),
    ];

    if (!hasAnyKeywords) {
        return null;
    }

    return (
        <section className="mt-6" data-testid="subjects-section" aria-labelledby="heading-keywords">
            <h3 id="heading-keywords" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Keywords</h3>
            <CollapsibleList
                items={allKeywords}
                itemLabel="keywords"
                renderItem={(item) => (
                    <li key={item.subject.id}>
                        <KeywordBadge subject={item.subject} style={item.group === 'free' ? FREE_KEYWORD_STYLE : undefined} />
                    </li>
                )}
                wrapper={(children) => {
                    // Split rendered children back into thesauri and free groups for visual separation.
                    // Children are in order: thesauri first, free second.
                    const childArray = Array.isArray(children) ? children : [children];
                    const thesauriCount = Math.min(thesauriKeywords.length, childArray.length);
                    const thesauriItems = childArray.slice(0, thesauriCount);
                    const freeItems = childArray.slice(thesauriCount);

                    return (
                        <>
                            {thesauriItems.length > 0 && (
                                <ul role="list" className="flex flex-wrap gap-2" data-testid="thesauri-keywords-list" data-slot="keyword-badge-list">
                                    {thesauriItems}
                                </ul>
                            )}
                            {thesauriItems.length > 0 && freeItems.length > 0 && <hr className="my-3 border-gray-200 dark:border-gray-700" />}
                            {freeItems.length > 0 && (
                                <ul role="list" className="flex flex-wrap gap-2" data-testid="keywords-list" data-slot="keyword-badge-list">
                                    {freeItems}
                                </ul>
                            )}
                        </>
                    );
                }}
            />
        </section>
    );
}
