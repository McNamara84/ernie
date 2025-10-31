import { FileJson, FileCode } from 'lucide-react';

interface Description {
    id: number;
    description: string;
    description_type: string | null;
}

interface Affiliation {
    id: number;
    value: string;
    ror_id: string | null;
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

interface Keyword {
    id: number;
    keyword: string;
}

interface ControlledKeyword {
    id: number;
    text: string;
    path: string;
    scheme: string;
    scheme_uri: string | null;
}

interface Author {
    id: number;
    position: number;
    roles: string[];
    affiliations: Affiliation[];
    authorable: {
        type: string;
        id: number;
        first_name?: string;
        last_name?: string;
        orcid?: string;
        name?: string;
    };
}

interface AbstractSectionProps {
    descriptions: Description[];
    authors: Author[];
    fundingReferences: FundingReference[];
    keywords: Keyword[];
    controlledKeywords: ControlledKeyword[];
    resourceId: number;
}

/**
 * Abstract Section
 * 
 * Zeigt die Abstract-Description, Authors, Funders, Keywords und Controlled Keywords an.
 */
export function AbstractSection({ 
    descriptions, 
    authors, 
    fundingReferences, 
    keywords,
    controlledKeywords,
    resourceId,
}: AbstractSectionProps) {
    // Finde die Abstract-Description (case-insensitive)
    const abstract = descriptions.find(
        (desc) => desc.description_type?.toLowerCase() === 'abstract',
    );

    if (!abstract) {
        return null;
    }

    // Filter authors with "Author" role
    const authorList = authors.filter((author) =>
        author.roles.includes('Author'),
    );

    // Group controlled keywords by scheme
    const gcmdScienceKeywords = controlledKeywords.filter(
        (kw) => kw.scheme === 'Science Keywords',
    );
    const gcmdPlatforms = controlledKeywords.filter(
        (kw) => kw.scheme === 'Platforms',
    );
    const gcmdInstruments = controlledKeywords.filter(
        (kw) => kw.scheme === 'Instruments',
    );
    const mslVocabularies = controlledKeywords.filter(
        (kw) => kw.scheme === 'msl',
    );

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">Abstract</h3>
            <div className="prose prose-sm max-w-none text-gray-700">
                <p className="mt-0 whitespace-pre-wrap">{abstract.description}</p>
            </div>

            {/* Authors Section */}
            {authorList.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        Authors
                    </h3>
                    <ul className="space-y-2">
                        {authorList.map((author) => {
                            const authorable = author.authorable;
                            const firstAffiliation = author.affiliations[0];
                            const isPerson = authorable.type === 'Person';

                            return (
                                <li
                                    key={author.id}
                                    className="flex items-center gap-1 text-sm text-gray-700"
                                >
                                    {/* Author Name */}
                                    {isPerson ? (
                                        <span>
                                            {authorable.last_name}, {authorable.first_name}
                                        </span>
                                    ) : (
                                        <span>{authorable.name}</span>
                                    )}

                                    {/* ORCID Icon (only for persons) */}
                                    {isPerson && authorable.orcid && (
                                        <a
                                            href={`https://orcid.org/${authorable.orcid}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="shrink-0"
                                            title={`ORCID: ${authorable.orcid}`}
                                        >
                                            <img
                                                src="/images/pid-icons/orcid-icon.png"
                                                alt="ORCID"
                                                className="h-4 w-4"
                                            />
                                        </a>
                                    )}

                                    {/* Affiliation */}
                                    {firstAffiliation && (
                                        <>
                                            {/* Semikolon nur wenn: Institution ODER Person ohne ORCID */}
                                            {(!isPerson || !authorable.orcid) && <span>; </span>}
                                            <span>{firstAffiliation.value}</span>

                                            {/* ROR Icon */}
                                            {firstAffiliation.ror_id && (
                                                <a
                                                    href={firstAffiliation.ror_id}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="shrink-0"
                                                    title={`ROR ID: ${firstAffiliation.ror_id}`}
                                                >
                                                    <img
                                                        src="/images/pid-icons/ror-icon.png"
                                                        alt="ROR"
                                                        className="h-4 w-4"
                                                    />
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
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        Funders
                    </h3>
                    <ul className="space-y-2">
                        {fundingReferences.map((funding) => (
                            <li
                                key={funding.id}
                                className="flex items-center gap-1 text-sm text-gray-700"
                            >
                                {/* Funder Name */}
                                <span>{funding.funder_name}</span>

                                {/* ROR Icon */}
                                {funding.funder_identifier_type === 'ROR' &&
                                    funding.funder_identifier && (
                                        <a
                                            href={funding.funder_identifier}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="shrink-0"
                                            title={`ROR ID: ${funding.funder_identifier}`}
                                        >
                                            <img
                                                src="/images/pid-icons/ror-icon.png"
                                                alt="ROR"
                                                className="h-4 w-4"
                                            />
                                        </a>
                                    )}

                                {/* Crossref Funder Icon */}
                                {funding.funder_identifier_type === 'Crossref Funder ID' &&
                                    funding.funder_identifier && (
                                        <a
                                            href={`https://doi.org/${funding.funder_identifier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="shrink-0"
                                            title={`Crossref Funder ID: ${funding.funder_identifier}`}
                                        >
                                            <img
                                                src="/images/pid-icons/crossref-funder.png"
                                                alt="Crossref Funder ID"
                                                className="h-4 w-4"
                                            />
                                        </a>
                                    )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Free Keywords Section */}
            {keywords.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        Free Keywords
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {keywords.map((keyword) => (
                            <span
                                key={keyword.id}
                                className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                style={{ backgroundColor: '#0C2A63' }}
                            >
                                {keyword.keyword}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Science Keywords Section */}
            {gcmdScienceKeywords.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        GCMD Science Keywords
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdScienceKeywords.map((keyword) => (
                            <span
                                key={keyword.id}
                                className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                style={{ backgroundColor: '#0C2A63' }}
                            >
                                {keyword.path}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Platforms Section */}
            {gcmdPlatforms.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        GCMD Platforms
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdPlatforms.map((keyword) => (
                            <span
                                key={keyword.id}
                                className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                style={{ backgroundColor: '#0C2A63' }}
                            >
                                {keyword.path}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* GCMD Instruments Section */}
            {gcmdInstruments.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        GCMD Instruments
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {gcmdInstruments.map((keyword) => (
                            <span
                                key={keyword.id}
                                className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                style={{ backgroundColor: '#0C2A63' }}
                            >
                                {keyword.path}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* MSL Vocabularies Section */}
            {mslVocabularies.length > 0 && (
                <div className="mt-6">
                    <h3 className="text-lg font-semibold text-gray-900">
                        MSL Vocabularies
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {mslVocabularies.map((keyword) => (
                            <span
                                key={keyword.id}
                                className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white"
                                style={{ backgroundColor: '#0C2A63' }}
                            >
                                {keyword.path}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* Download Metadata Section */}
            <div className="mt-6">
                <h3 className="text-lg font-semibold text-gray-900">
                    Download Metadata
                </h3>
                <div className="flex items-center gap-4">
                    {/* DataCite Logo */}
                    <img
                        src="/images/datacite-logo.png"
                        alt="DataCite"
                        className="h-8"
                    />

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
