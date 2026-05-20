import { Clock, FlaskConical, Globe, Leaf, type LucideIcon, Microscope, Satellite, Search } from 'lucide-react';

import {
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
const THESAURUS_DEFINITIONS: { scheme: string; icon: LucideIcon }[] = [
    { scheme: SCHEME_GCMD_SCIENCE, icon: Globe },
    { scheme: SCHEME_GCMD_PLATFORMS, icon: Satellite },
    { scheme: SCHEME_GCMD_INSTRUMENTS, icon: Microscope },
    { scheme: SCHEME_MSL, icon: FlaskConical },
    { scheme: SCHEME_GEMET, icon: Leaf },
    { scheme: SCHEME_ICS_CHRONOSTRAT, icon: Clock },
];

const THESAURUS_SCHEMES = new Set(THESAURUS_DEFINITIONS.map((d) => d.scheme));
const SCHEME_CONFIG = Object.fromEntries(THESAURUS_DEFINITIONS.map((d) => [d.scheme, { icon: d.icon }]));

const LINKED_KEYWORD_STYLE = {
    bg: 'bg-gfz-primary',
    text: 'text-gfz-primary-foreground',
    actionTone: 'text-white/80 hover:text-white focus-visible:text-white',
};
const FREE_KEYWORD_STYLE = {
    bg: 'bg-sky-100 dark:bg-sky-900/40',
    text: 'text-sky-900 dark:text-sky-100',
    actionTone: 'text-sky-900/75 hover:text-sky-900 focus-visible:text-sky-900 dark:text-sky-100/80 dark:hover:text-sky-100 dark:focus-visible:text-sky-100',
};
const EMPTY_PORTAL_FILTERS: PortalFilters = {
    query: null,
    type: [],
    keywords: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};
const THESAURUS_NOTATION_DELIMITER = '::';

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

function getThesaurusKeywordToken(subject: LandingPageSubject): string | null {
    const valueUri = subject.value_uri?.trim();
    if (valueUri) {
        return valueUri;
    }

    const subjectScheme = subject.subject_scheme?.trim();
    const classificationCode = subject.classification_code?.trim();

    if (!subjectScheme || !classificationCode) {
        return null;
    }

    return `${subjectScheme}${THESAURUS_NOTATION_DELIMITER}${classificationCode}`;
}

function getPortalUrl(subject: LandingPageSubject): string | null {
    if (!subject.subject_scheme || subject.subject_scheme === '') {
        return buildPortalFilterUrl({
            ...EMPTY_PORTAL_FILTERS,
            freeKeywords: [subject.subject],
        });
    }

    const thesaurusKeyword = getThesaurusKeywordToken(subject);
    if (thesaurusKeyword) {
        return buildPortalFilterUrl({
            ...EMPTY_PORTAL_FILTERS,
            thesaurusKeywords: [thesaurusKeyword],
        });
    }

    return buildPortalFilterUrl({
        ...EMPTY_PORTAL_FILTERS,
        keywords: [subject.subject],
    });
}

/**
 * Renders a keyword badge that links to the portal with the keyword as filter.
 */
function KeywordBadge({ subject, style }: { subject: LandingPageSubject; style: { bg: string; text: string; actionTone: string } }) {
    const portalUrl = getPortalUrl(subject);
    const config = SCHEME_CONFIG[subject.subject_scheme ?? ''];
    const { bg, text, actionTone } = style;
    const BadgeIcon = config?.icon;
    const fullPath = getFullPath(subject);
    const displayLabel = getDisplayLabel(subject);
    const searchPrompt = `Search for ${displayLabel} in the portal`;
    const linkedBadgeClass = `inline-flex items-center rounded-full ${bg} ${text} shadow-sm`;
    const labelClass = `inline-flex items-center gap-1 px-3 py-1 text-xs font-medium ${portalUrl ? 'pe-1 transition-opacity hover:opacity-85 focus-visible:opacity-85' : `rounded-full cursor-default ${bg} ${text}`}`;
    const actionClass = `inline-flex items-center justify-center px-1.5 py-1 text-xs transition-colors ${actionTone}`;

    const badgeContent = (
        <>
            {BadgeIcon && <BadgeIcon className="h-3 w-3" aria-hidden="true" />}
            {displayLabel}
        </>
    );

    const label = portalUrl ? (
        <a
            href={portalUrl}
            className={labelClass}
            title={fullPath ?? undefined}
        >
            {badgeContent}
        </a>
    ) : (
        <span
            className={labelClass}
            title={fullPath ?? undefined}
        >
            {badgeContent}
        </span>
    );

    if (!portalUrl) {
        return label;
    }

    return (
        <span className={linkedBadgeClass} data-slot="keyword-badge">
            {label}
            <a
                href={portalUrl}
                className={actionClass}
                title={searchPrompt}
                aria-label={searchPrompt}
                data-slot="keyword-badge-action"
            >
                <Search className="h-3 w-3" aria-hidden="true" />
            </a>
        </span>
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
                        <KeywordBadge subject={item.subject} style={item.group === 'free' ? FREE_KEYWORD_STYLE : LINKED_KEYWORD_STYLE} />
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
