import { ExternalLink, FileCode, FileJson } from 'lucide-react';

import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageCreatorable,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageSubject,
} from '@/types/landing-page';

interface AbstractSectionProps {
    descriptions: LandingPageDescription[];
    creators: LandingPageCreator[];
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    subjects: LandingPageSubject[];
    resourceId: number;
}

/**
 * Renders a keyword badge that links to the portal with the keyword as filter.
 */
function KeywordBadge({ subject }: { subject: LandingPageSubject }) {
    const portalUrl = `/portal?keywords[]=${encodeURIComponent(subject.subject)}`;

    return (
        <a
            href={portalUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 rounded-full bg-gfz-primary px-3 py-1 text-xs font-medium text-gfz-primary-foreground transition-opacity hover:opacity-80"
            title={`Search for "${subject.subject}" in the portal`}
        >
            {subject.subject}
            <ExternalLink className="h-3 w-3 opacity-70" />
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
export function AbstractSection({ descriptions, creators, contributors, fundingReferences, subjects, resourceId }: AbstractSectionProps) {
    // Finde die Abstract-Description (case-insensitive)
    const abstract = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'abstract');
    const methods = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'methods');

    if (!abstract) {
        return null;
    }

    // Group subjects by scheme
    const freeKeywords = subjects.filter((s) => !s.subject_scheme || s.subject_scheme === '');
    const gcmdScienceKeywords = subjects.filter((s) => s.subject_scheme === 'Science Keywords');
    const gcmdPlatforms = subjects.filter((s) => s.subject_scheme === 'Platforms');
    const gcmdInstruments = subjects.filter((s) => s.subject_scheme === 'Instruments');
    const mslVocabularies = subjects.filter((s) => s.subject_scheme === 'EPOS MSL vocabulary');

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" data-testid="abstract-section">
            <h3 className="text-lg font-semibold text-gray-900">Abstract</h3>
            <div className="prose prose-sm max-w-none text-gray-700">
                <p className="mt-0 whitespace-pre-wrap" data-testid="abstract-text">
                    {abstract.value}
                </p>
            </div>

            {/* Methods Section */}
            {methods && (
                <div className="mt-6" data-testid="methods-section">
                    <h3 className="text-lg font-semibold text-gray-900">Methods</h3>
                    <div className="prose prose-sm max-w-none text-gray-700">
                        <p className="mt-0 whitespace-pre-wrap" data-testid="methods-text">
                            {methods.value}
                        </p>
                    </div>
                </div>
            )}

            {/* Creators Section */}
            {creators.length > 0 && (
                <div className="mt-6" data-testid="creators-section">
                    <h3 className="text-lg font-semibold text-gray-900">Creators</h3>
                    <ul className="space-y-2" data-testid="creators-list">
                        {creators.map((creator) => {
                            const creatorable = creator.creatorable;
                            const firstAffiliation = creator.affiliations[0];
                            const isPerson = creatorable.type === 'Person';
                            const hasOrcid = isPerson && creatorable.name_identifier && creatorable.name_identifier_scheme === 'ORCID';

                            return (
                                <li key={creator.id} className="flex items-center gap-1 text-sm text-gray-700">
                                    {/* Creator Name */}
                                    {isPerson ? (
                                        <span>
                                            {formatPersonName(creatorable.family_name, creatorable.given_name)}
                                        </span>
                                    ) : (
                                        <span>{creatorable.name}</span>
                                    )}

                                    {/* ORCID Icon (only for persons) */}
                                    {hasOrcid && (
                                        <a
                                            href={`https://orcid.org/${creatorable.name_identifier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="shrink-0"
                                            title={`ORCID: ${creatorable.name_identifier}`}
                                        >
                                            <img src="/images/pid-icons/orcid-icon.png" alt="ORCID" className="h-4 w-4" />
                                        </a>
                                    )}

                                    {/* Affiliation */}
                                    {firstAffiliation && (
                                        <>
                                            {/* Semikolon nur wenn: Institution ODER Person ohne ORCID */}
                                            {(!isPerson || !hasOrcid) && <span>; </span>}
                                            <span>{firstAffiliation.name}</span>

                                            {/* ROR Icon */}
                                            {firstAffiliation.affiliation_identifier && firstAffiliation.affiliation_identifier_scheme === 'ROR' && (
                                                <a
                                                    href={firstAffiliation.affiliation_identifier}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="shrink-0"
                                                    title={`ROR ID: ${firstAffiliation.affiliation_identifier}`}
                                                >
                                                    <img src="/images/pid-icons/ror-icon.png" alt="ROR" className="h-4 w-4" />
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
                    <h3 className="text-lg font-semibold text-gray-900">Contributors</h3>
                    <ul className="space-y-2" data-testid="contributors-list">
                        {contributors.map((contributor) => {
                            const contributorable = contributor.contributorable;
                            const firstAffiliation = contributor.affiliations[0];
                            const isPerson = contributorable.type === 'Person';
                            const hasOrcid = isPerson && contributorable.name_identifier && contributorable.name_identifier_scheme === 'ORCID';

                            return (
                                <li key={contributor.id} className="flex items-center gap-1 text-sm text-gray-700">
                                    {/* Contributor Name */}
                                    {isPerson ? (
                                        <span>
                                            {formatPersonName(contributorable.family_name, contributorable.given_name)}
                                        </span>
                                    ) : (
                                        <span>{contributorable.name}</span>
                                    )}

                                    {/* ORCID Icon (only for persons) */}
                                    {hasOrcid && (
                                        <a
                                            href={`https://orcid.org/${contributorable.name_identifier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="shrink-0"
                                            title={`ORCID: ${contributorable.name_identifier}`}
                                        >
                                            <img src="/images/pid-icons/orcid-icon.png" alt="ORCID" className="h-4 w-4" />
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
                                                    className="shrink-0"
                                                    title={`ROR ID: ${firstAffiliation.affiliation_identifier}`}
                                                >
                                                    <img src="/images/pid-icons/ror-icon.png" alt="ROR" className="h-4 w-4" />
                                                </a>
                                            )}
                                        </>
                                    )}

                                    {/* Contributor Types */}
                                    {contributor.contributor_types.length > 0 && (
                                        <span className="text-gray-500">({contributor.contributor_types.join(', ')})</span>
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
                    <h3 className="text-lg font-semibold text-gray-900">Funders</h3>
                    <ul className="space-y-2" data-testid="funding-list">
                        {fundingReferences.map((funding) => (
                            <li key={funding.id} className="flex items-center gap-1 text-sm text-gray-700">
                                {/* Funder Name */}
                                <span>{funding.funder_name}</span>

                                {/* ROR Icon */}
                                {funding.funder_identifier_type === 'ROR' && funding.funder_identifier && (
                                    <a
                                        href={funding.funder_identifier}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="shrink-0"
                                        title={`ROR ID: ${funding.funder_identifier}`}
                                    >
                                        <img src="/images/pid-icons/ror-icon.png" alt="ROR" className="h-4 w-4" />
                                    </a>
                                )}

                                {/* Crossref Funder Icon */}
                                {funding.funder_identifier_type === 'Crossref Funder ID' && funding.funder_identifier && (
                                    <a
                                        href={`https://doi.org/${funding.funder_identifier}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="shrink-0"
                                        title={`Crossref Funder ID: ${funding.funder_identifier}`}
                                    >
                                        <img src="/images/pid-icons/crossref-funder.png" alt="Crossref Funder ID" className="h-4 w-4" />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Free Keywords Section */}
            {freeKeywords.length > 0 && (
                <div className="mt-6" data-testid="subjects-section">
                    <h3 className="text-lg font-semibold text-gray-900">Free Keywords</h3>
                    <div className="flex flex-wrap gap-2" data-testid="keywords-list">
                        {freeKeywords.map((subject) => (
                            <KeywordBadge key={subject.id} subject={subject} />
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Science Keywords Section */}
            {gcmdScienceKeywords.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">GCMD Science Keywords</h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdScienceKeywords.map((subject) => (
                            <KeywordBadge key={subject.id} subject={subject} />
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Platforms Section */}
            {gcmdPlatforms.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">GCMD Platforms</h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdPlatforms.map((subject) => (
                            <KeywordBadge key={subject.id} subject={subject} />
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Instruments Section */}
            {gcmdInstruments.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">GCMD Instruments</h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdInstruments.map((subject) => (
                            <KeywordBadge key={subject.id} subject={subject} />
                        ))}
                    </div>
                </div>
            )}

            {/* MSL Vocabularies Section */}
            {mslVocabularies.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">MSL Vocabularies</h3>
                    <div className="flex flex-wrap gap-2">
                        {mslVocabularies.map((subject) => (
                            <KeywordBadge key={subject.id} subject={subject} />
                        ))}
                    </div>
                </div>
            )}

            {/* Download Metadata Section */}
            <div className="mt-6">
                <h3 className="text-lg font-semibold text-gray-900">Download Metadata</h3>
                <div className="flex items-center gap-4">
                    {/* DataCite Logo */}
                    <img src="/images/datacite-logo.png" alt="DataCite" className="h-8" />

                    {/* XML Download Button */}
                    <a
                        href={`/resources/${resourceId}/export-datacite-xml`}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                        title="Download as DataCite XML"
                    >
                        <FileCode className="h-5 w-5" />
                        XML
                    </a>

                    {/* JSON Download Button */}
                    <a
                        href={`/resources/${resourceId}/export-datacite-json`}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                        title="Download as DataCite JSON"
                    >
                        <FileJson className="h-5 w-5" />
                        JSON
                    </a>
                </div>
            </div>
        </div>
    );
}
