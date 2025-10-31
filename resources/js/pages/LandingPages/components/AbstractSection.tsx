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
}

/**
 * Abstract Section
 * 
 * Zeigt die Abstract-Description und Authors an.
 */
export function AbstractSection({ descriptions, authors }: AbstractSectionProps) {
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
                                                    href={`https://ror.org/${firstAffiliation.ror_id}`}
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
        </div>
    );
}
