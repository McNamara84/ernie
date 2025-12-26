import { FileCode, FileJson } from 'lucide-react';

interface Description {
    id: number;
    value: string;
    description_type: string | null;
}

interface Affiliation {
    id: number;
    name: string;
    affiliation_identifier: string | null;
    affiliation_identifier_scheme: string | null;
}

interface FundingReference {
    id: number;
    funder_name: string;
    funder_identifier: string | null;
    funder_identifier_type: string | null;
    award_number: string | null;
    award_uri: string | null;
    award_title: string | null;
    position: number;
}

interface Subject {
    id: number;
    subject: string;
    subject_scheme: string | null;
    scheme_uri: string | null;
    value_uri: string | null;
    classification_code: string | null;
}

interface Creator {
    id: number;
    position: number;
    affiliations: Affiliation[];
    creatorable: {
        type: string;
        id: number;
        given_name?: string;
        family_name?: string;
        name_identifier?: string;
        name_identifier_scheme?: string;
        name?: string;
    };
}

interface Contributor {
    id: number;
    position: number;
    contributor_type: string;
    affiliations: Affiliation[];
    contributorable: {
        type: string;
        id: number;
        given_name?: string;
        family_name?: string;
        name_identifier?: string;
        name_identifier_scheme?: string;
        name?: string;
    };
}

interface AbstractSectionProps {
    descriptions: Description[];
    creators: Creator[];
    contributors?: Contributor[];
    fundingReferences: FundingReference[];
    subjects: Subject[];
    resourceId: number;
}

/**
 * Abstract Section
 *
 * Zeigt die Abstract-Description, Creators, Funders und Subjects an.
 */
export function AbstractSection({ descriptions, creators, contributors = [], fundingReferences, subjects, resourceId }: AbstractSectionProps) {
    // Finde die Abstract-Description (case-insensitive)
    const abstract = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'abstract');

    if (!abstract) {
        return null;
    }

    // Group subjects by scheme
    const freeKeywords = subjects.filter((s) => !s.subject_scheme || s.subject_scheme === '');
    const gcmdScienceKeywords = subjects.filter((s) => s.subject_scheme === 'Science Keywords');
    const gcmdPlatforms = subjects.filter((s) => s.subject_scheme === 'Platforms');
    const gcmdInstruments = subjects.filter((s) => s.subject_scheme === 'Instruments');
    const mslVocabularies = subjects.filter((s) => s.subject_scheme === 'msl');

    return (
        <div data-testid="abstract-section" className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">Abstract</h3>
            <div className="prose prose-sm max-w-none text-gray-700">
                <p className="mt-0 whitespace-pre-wrap">{abstract.value}</p>
            </div>

            {/* Creators Section */}
            {creators.length > 0 && (
                <div data-testid="creators-section" className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">Creators</h3>
                    <ul data-testid="creators-list" className="space-y-2">
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
                                            {`${creatorable.given_name ?? ''} ${creatorable.family_name ?? ''}`.trim()}
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
                <div data-testid="contributors-section" className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">Contributors</h3>
                    <ul data-testid="contributors-list" className="space-y-2">
                        {contributors.map((contributor) => {
                            const contributorable = contributor.contributorable;
                            const firstAffiliation = contributor.affiliations[0];
                            const isPerson = contributorable.type === 'Person';
                            const hasOrcid = isPerson && contributorable.name_identifier && contributorable.name_identifier_scheme === 'ORCID';

                            return (
                                <li key={contributor.id} className="flex flex-wrap items-center gap-1 text-sm text-gray-700">
                                    <span className="font-medium">{contributor.contributor_type}</span>
                                    <span>:</span>

                                    {isPerson ? (
                                        <span>
                                            {`${contributorable.given_name ?? ''} ${contributorable.family_name ?? ''}`.trim()}
                                        </span>
                                    ) : (
                                        <span>{contributorable.name}</span>
                                    )}

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

                                    {firstAffiliation && (
                                        <>
                                            <span>; </span>
                                            <span>{firstAffiliation.name}</span>

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

            {/* Funders Section */}
            {fundingReferences.length > 0 && (
                <div data-testid="funding-section" className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">Funders</h3>
                    <ul className="space-y-2">
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

            {(freeKeywords.length > 0 ||
                gcmdScienceKeywords.length > 0 ||
                gcmdPlatforms.length > 0 ||
                gcmdInstruments.length > 0 ||
                mslVocabularies.length > 0) && (
                <div data-testid="subjects-section">
                    {/* Free Keywords Section */}
                    {freeKeywords.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-lg font-semibold text-gray-900">Free Keywords</h3>
                            <div data-testid="keywords-list" className="flex flex-wrap gap-2">
                                {freeKeywords.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                        style={{ backgroundColor: '#0C2A63' }}
                                    >
                                        {subject.subject}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* GCMD Science Keywords Section */}
                    {gcmdScienceKeywords.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-lg font-semibold text-gray-900">GCMD Science Keywords</h3>
                            <div data-testid="keywords-list" className="flex flex-wrap gap-2">
                                {gcmdScienceKeywords.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                        style={{ backgroundColor: '#0C2A63' }}
                                    >
                                        {subject.subject}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* GCMD Platforms Section */}
                    {gcmdPlatforms.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-lg font-semibold text-gray-900">GCMD Platforms</h3>
                            <div data-testid="keywords-list" className="flex flex-wrap gap-2">
                                {gcmdPlatforms.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                        style={{ backgroundColor: '#0C2A63' }}
                                    >
                                        {subject.subject}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* GCMD Instruments Section */}
                    {gcmdInstruments.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-lg font-semibold text-gray-900">GCMD Instruments</h3>
                            <div data-testid="keywords-list" className="flex flex-wrap gap-2">
                                {gcmdInstruments.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                        style={{ backgroundColor: '#0C2A63' }}
                                    >
                                        {subject.subject}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* MSL Vocabularies Section */}
                    {mslVocabularies.length > 0 && (
                        <div className="mt-6">
                            <h3 className="text-lg font-semibold text-gray-900">MSL Vocabularies</h3>
                            <div data-testid="keywords-list" className="flex flex-wrap gap-2">
                                {mslVocabularies.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                        style={{ backgroundColor: '#0C2A63' }}
                                    >
                                        {subject.subject}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
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
