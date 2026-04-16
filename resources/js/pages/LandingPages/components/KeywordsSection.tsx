import { Clock, ExternalLink, FlaskConical, Globe, Leaf, type LucideIcon, Microscope, Satellite } from 'lucide-react';

import {
    getSchemeLabel,
    SCHEME_GCMD_INSTRUMENTS,
    SCHEME_GCMD_PLATFORMS,
    SCHEME_GCMD_SCIENCE,
    SCHEME_GEMET,
    SCHEME_ICS_CHRONOSTRAT,
    SCHEME_MSL,
} from '@/lib/keyword-schemes';
import type { LandingPageSubject } from '@/types/landing-page';

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

/**
 * Renders a keyword badge that links to the portal with the keyword as filter.
 */
function KeywordBadge({ subject, style, icon: Icon }: { subject: LandingPageSubject; style?: { bg: string; text: string }; icon?: LucideIcon }) {
    const portalUrl = `/portal?keywords[]=${encodeURIComponent(subject.subject)}`;
    const config = SCHEME_CONFIG[subject.subject_scheme ?? ''];
    const { bg, text } = style ?? config ?? FREE_KEYWORD_STYLE;
    const BadgeIcon = Icon ?? config?.icon;
    const schemeLabel = getSchemeLabel(subject.subject_scheme ?? null);

    return (
        <a
            href={portalUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition-opacity hover:opacity-80 ${bg} ${text}`}
            title={`Search for "${subject.subject}" in the portal`}
            aria-label={`${subject.subject} (${schemeLabel})`}
        >
            {BadgeIcon && <BadgeIcon className="h-3 w-3" aria-hidden="true" />}
            {subject.subject}
            <ExternalLink className="h-3 w-3 opacity-70" aria-hidden="true" />
        </a>
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
            <h2 id="heading-keywords" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Keywords</h2>
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
                                <ul role="list" className="flex flex-wrap gap-2" data-testid="thesauri-keywords-list">
                                    {thesauriItems}
                                </ul>
                            )}
                            {thesauriItems.length > 0 && freeItems.length > 0 && <hr className="my-3 border-gray-200 dark:border-gray-700" />}
                            {freeItems.length > 0 && (
                                <ul role="list" className="flex flex-wrap gap-2" data-testid="keywords-list">
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
