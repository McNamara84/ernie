import { Braces, Clock, ExternalLink, FileCode, FileJson, FlaskConical, Globe, Leaf, type LucideIcon, Microscope, Satellite } from 'lucide-react';

import {
    getSchemeLabel,
    SCHEME_GCMD_INSTRUMENTS,
    SCHEME_GCMD_PLATFORMS,
    SCHEME_GCMD_SCIENCE,
    SCHEME_GEMET,
    SCHEME_ICS_CHRONOSTRAT,
    SCHEME_MSL,
} from '@/lib/keyword-schemes';
import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageSubject,
} from '@/types/landing-page';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';

interface AbstractSectionProps {
    descriptions: LandingPageDescription[];
    creators: LandingPageCreator[];
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    subjects: LandingPageSubject[];
    resourceId: number;
    /** Public JSON-LD export URL for landing pages (avoids auth-protected routes) */
    jsonLdExportUrl?: string;
}

/** Single source of truth: ordered thesaurus definitions with badge styling and icons */
const THESAURUS_DEFINITIONS: { scheme: string; icon: LucideIcon; bgClass: string; textClass: string }[] = [
    { scheme: SCHEME_GCMD_SCIENCE, icon: Globe, bgClass: 'bg-blue-600 dark:bg-blue-500', textClass: 'text-white' },
    { scheme: SCHEME_GCMD_PLATFORMS, icon: Satellite, bgClass: 'bg-emerald-600 dark:bg-emerald-500', textClass: 'text-white' },
    { scheme: SCHEME_GCMD_INSTRUMENTS, icon: Microscope, bgClass: 'bg-amber-600 dark:bg-amber-500', textClass: 'text-white' },
    { scheme: SCHEME_MSL, icon: FlaskConical, bgClass: 'bg-purple-600 dark:bg-purple-500', textClass: 'text-white' },
    { scheme: SCHEME_GEMET, icon: Leaf, bgClass: 'bg-rose-600 dark:bg-rose-500', textClass: 'text-white' },
    { scheme: SCHEME_ICS_CHRONOSTRAT, icon: Clock, bgClass: 'bg-teal-600 dark:bg-teal-500', textClass: 'text-white' },
];

const THESAURUS_SCHEMES = new Set(THESAURUS_DEFINITIONS.map((d) => d.scheme));
const SCHEME_CONFIG = Object.fromEntries(
    THESAURUS_DEFINITIONS.map((d) => [d.scheme, { bg: d.bgClass, text: d.textClass, icon: d.icon }]),
);

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

/**
 * Formats a person's name defensively, handling null values.
 */
function formatPersonName(familyName: string | null, givenName: string | null): string {
    if (familyName && givenName) return `${familyName}, ${givenName}`;
    if (familyName) return familyName;
    if (givenName) return givenName;
    return 'Unknown';
}

/**
 * Abstract Section
 *
 * Renders the Abstract, Methods (if available), Creators, Contributors,
 * Funders, Subjects/Keywords, and Download Metadata sections.
 */
export function AbstractSection({ descriptions, creators, contributors, fundingReferences, subjects, resourceId, jsonLdExportUrl }: AbstractSectionProps) {
    const { ref, isVisible } = useFadeInOnScroll();

    // Find the Abstract description (case-insensitive)
    const abstract = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'abstract');
    const methods = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'methods');

    if (!abstract) {
        return null;
    }

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

    return (
        <section
            ref={ref}
            aria-labelledby="heading-abstract"
            inert={!isVisible || undefined}
            className={`rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all duration-200 ease-in-out hover:shadow-md dark:border-gray-700 dark:bg-gray-800 ${isVisible ? 'opacity-100' : 'opacity-0'}`}
            data-testid="abstract-section"
        >
            <h2 id="heading-abstract" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Abstract</h2>
            <div className="prose prose-sm max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">
                <p className="mt-0 whitespace-pre-wrap" data-testid="abstract-text">
                    {abstract.value}
                </p>
            </div>

            {/* Methods Section */}
            {methods && (
                <div className="mt-6" data-testid="methods-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Methods</h3>
                    <div className="prose prose-sm max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">
                        <p className="mt-0 whitespace-pre-wrap" data-testid="methods-text">
                            {methods.value}
                        </p>
                    </div>
                </div>
            )}

            {/* Creators Section */}
            {creators.length > 0 && (
                <div className="mt-6" data-testid="creators-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Creators</h3>
                    <ul className="space-y-2" data-testid="creators-list">
                        {creators.map((creator) => {
                            const creatorable = creator.creatorable;
                            const firstAffiliation = creator.affiliations[0];
                            const isPerson = creatorable.type === 'Person';
                            const hasOrcid = isPerson && creatorable.name_identifier && creatorable.name_identifier_scheme === 'ORCID';
                            const personName = isPerson
                                ? formatPersonName(creatorable.family_name, creatorable.given_name)
                                : creatorable.name;

                            return (
                                <li key={creator.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                                    {/* Creator Name */}
                                    <span>{personName}</span>

                                    {/* ORCID Icon (only for persons) */}
                                    {hasOrcid && (
                                        <a
                                            href={`https://orcid.org/${creatorable.name_identifier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                            aria-label={`ORCID profile of ${personName}`}
                                        >
                                            <img src="/images/pid-icons/orcid-icon.png" alt="" className="h-4 w-4" />
                                        </a>
                                    )}

                                    {/* Affiliation */}
                                    {firstAffiliation && (
                                        <>
                                            {(!isPerson || !hasOrcid) && <span>; </span>}
                                            <span>{firstAffiliation.name}</span>

                                            {/* ROR Icon */}
                                            {firstAffiliation.affiliation_identifier && firstAffiliation.affiliation_identifier_scheme === 'ROR' && (
                                                <a
                                                    href={firstAffiliation.affiliation_identifier}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                                    aria-label={`ROR profile of ${firstAffiliation.name}`}
                                                >
                                                    <img src="/images/pid-icons/ror-icon.png" alt="" className="h-4 w-4" />
                                                </a>
                                            )}
                                        </>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {/* Contributors Section */}
            {contributors.length > 0 && (
                <div className="mt-6" data-testid="contributors-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Contributors</h3>
                    <ul className="space-y-2" data-testid="contributors-list">
                        {contributors.map((contributor) => {
                            const contributorable = contributor.contributorable;
                            const firstAffiliation = contributor.affiliations[0];
                            const isPerson = contributorable.type === 'Person';
                            const hasOrcid = isPerson && contributorable.name_identifier && contributorable.name_identifier_scheme === 'ORCID';
                            const personName = isPerson
                                ? formatPersonName(contributorable.family_name, contributorable.given_name)
                                : contributorable.name;

                            return (
                                <li key={contributor.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                                    {/* Contributor Name */}
                                    <span>{personName}</span>

                                    {/* ORCID Icon (only for persons) */}
                                    {hasOrcid && (
                                        <a
                                            href={`https://orcid.org/${contributorable.name_identifier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                            aria-label={`ORCID profile of ${personName}`}
                                        >
                                            <img src="/images/pid-icons/orcid-icon.png" alt="" className="h-4 w-4" />
                                        </a>
                                    )}

                                    {/* Affiliation */}
                                    {firstAffiliation && (
                                        <>
                                            {(!isPerson || !hasOrcid) && <span>; </span>}
                                            <span>{firstAffiliation.name}</span>

                                            {/* ROR Icon */}
                                            {firstAffiliation.affiliation_identifier && firstAffiliation.affiliation_identifier_scheme === 'ROR' && (
                                                <a
                                                    href={firstAffiliation.affiliation_identifier}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                                    aria-label={`ROR profile of ${firstAffiliation.name}`}
                                                >
                                                    <img src="/images/pid-icons/ror-icon.png" alt="" className="h-4 w-4" />
                                                </a>
                                            )}
                                        </>
                                    )}

                                    {/* Contributor Types */}
                                    {contributor.contributor_types.length > 0 && (
                                        <span className="text-gray-500 dark:text-gray-400">({contributor.contributor_types.join(', ')})</span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {/* Funders Section */}
            {fundingReferences.length > 0 && (
                <div className="mt-6" data-testid="funding-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Funders</h3>
                    <ul className="space-y-2" data-testid="funding-list">
                        {fundingReferences.map((funding) => (
                            <li key={funding.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                                {/* Funder Name */}
                                <span>{funding.funder_name}</span>

                                {/* ROR Icon */}
                                {funding.funder_identifier_type === 'ROR' && funding.funder_identifier && (
                                    <a
                                        href={funding.funder_identifier}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                        aria-label={`ROR profile of ${funding.funder_name}`}
                                    >
                                        <img src="/images/pid-icons/ror-icon.png" alt="" className="h-4 w-4" />
                                    </a>
                                )}

                                {/* Crossref Funder Icon */}
                                {funding.funder_identifier_type === 'Crossref Funder ID' && funding.funder_identifier && (
                                    <a
                                        href={`https://doi.org/${funding.funder_identifier}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center -m-3 p-3"
                                        aria-label={`Crossref Funder ID for ${funding.funder_name}`}
                                    >
                                        <img src="/images/pid-icons/crossref-funder.png" alt="" className="h-4 w-4" />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Keywords Section (Thesauri + Free Keywords) */}
            {hasAnyKeywords && (
                <div className="mt-6" data-testid="subjects-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Keywords</h3>

                    {/* Thesauri Keywords */}
                    {thesauriKeywords.length > 0 && (
                        <div className="flex flex-wrap gap-2" data-testid="thesauri-keywords-list">
                            {thesauriKeywords.map((subject) => (
                                <KeywordBadge key={subject.id} subject={subject} />
                            ))}
                        </div>
                    )}

                    {/* Separator between thesauri and free keywords */}
                    {thesauriKeywords.length > 0 && freeKeywords.length > 0 && (
                        <hr className="my-3 border-gray-200 dark:border-gray-700" />
                    )}

                    {/* Free Keywords */}
                    {freeKeywords.length > 0 && (
                        <div className="flex flex-wrap gap-2" data-testid="keywords-list">
                            {freeKeywords.map((subject) => (
                                <KeywordBadge key={subject.id} subject={subject} style={FREE_KEYWORD_STYLE} />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Download Metadata Section */}
            <div className="mt-6">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Download Metadata</h3>
                <div className="flex items-center gap-4">
                    {/* DataCite Logo */}
                    <img src="/images/datacite-logo.png" alt="DataCite" className="h-8 dark:brightness-200 dark:invert" />

                    {/* XML Download Button */}
                    <a
                        href={`/resources/${resourceId}/export-datacite-xml`}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        title="Download as DataCite XML"
                    >
                        <FileCode className="h-5 w-5" aria-hidden="true" />
                        XML
                    </a>

                    {/* JSON Download Button */}
                    <a
                        href={`/resources/${resourceId}/export-datacite-json`}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        title="Download as DataCite JSON"
                    >
                        <FileJson className="h-5 w-5" aria-hidden="true" />
                        JSON
                    </a>

                    {/* JSON-LD Download Button */}
                    <a
                        href={jsonLdExportUrl ?? `/resources/${resourceId}/export-jsonld`}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        title="Download as JSON-LD (Linked Data)"
                    >
                        <Braces className="h-5 w-5" aria-hidden="true" />
                        JSON-LD
                    </a>
                </div>
            </div>
        </section>
    );
}
