import { Building2, ExternalLink, Mail, User } from 'lucide-react';

// Type definitions for Authors (will be moved to shared types later)
interface Affiliation {
    id: number;
    organization_name: string;
    ror_id?: string | null;
    [key: string]: unknown;
}

interface Role {
    id: number;
    name: string;
    slug: string;
    [key: string]: unknown;
}

interface Person {
    given_name?: string;
    family_name?: string;
    orcid?: string | null;
    [key: string]: unknown;
}

interface Institution {
    name: string;
    identifier?: string | null;
    identifier_type?: string | null;
    [key: string]: unknown;
}

interface Author {
    id: number;
    authorable_type: string;
    authorable: Person | Institution;
    affiliations?: Affiliation[];
    roles?: Role[];
    position?: string | null;
    email?: string | null;
    website?: string | null;
    [key: string]: unknown;
}

interface Resource {
    authors?: Author[];
    [key: string]: unknown;
}

interface AuthorsListProps {
    resource: Resource;
    /** Show only authors with specific role (e.g., "Author" or "ContactPerson") */
    filterByRole?: string;
    /** Custom heading text */
    heading?: string;
    /** Show email addresses */
    showEmail?: boolean;
    /** Show websites */
    showWebsite?: boolean;
    /** Maximum number of authors to display (0 = show all) */
    maxAuthors?: number;
}

/**
 * AuthorsList Component
 * 
 * Displays a formatted list of dataset authors/contributors with:
 * - ORCID links with icon
 * - Affiliations with ROR links
 * - Positions/Roles badges
 * - Email addresses (optional)
 * - Website links (optional)
 * - Responsive grid layout
 * 
 * Supports both Person and Institution authors.
 */
export default function AuthorsList({
    resource,
    filterByRole,
    heading = 'Authors',
    showEmail = false,
    showWebsite = false,
    maxAuthors = 0,
}: AuthorsListProps) {
    if (!resource.authors || resource.authors.length === 0) {
        return null;
    }

    // Filter authors by role if specified
    let authors = resource.authors;
    if (filterByRole) {
        authors = authors.filter((author) =>
            author.roles?.some((role) => role.slug === filterByRole),
        );
    }

    // Limit number of authors if specified
    if (maxAuthors > 0) {
        authors = authors.slice(0, maxAuthors);
    }

    if (authors.length === 0) {
        return null;
    }

    /**
     * Check if author is a Person
     */
    const isPerson = (author: Author): author is Author & { authorable: Person } => {
        return author.authorable_type === 'App\\Models\\Person';
    };

    /**
     * Check if author is an Institution
     */
    const isInstitution = (author: Author): author is Author & { authorable: Institution } => {
        return author.authorable_type === 'App\\Models\\Institution';
    };

    /**
     * Format author name
     */
    const getAuthorName = (author: Author): string => {
        if (isPerson(author)) {
            const { given_name, family_name } = author.authorable;
            return [given_name, family_name].filter(Boolean).join(' ') || 'Unknown Author';
        }
        
        if (isInstitution(author)) {
            return author.authorable.name || 'Unknown Institution';
        }

        return 'Unknown Author';
    };

    return (
        <section className="space-y-4" aria-label={heading}>
            {/* Heading */}
            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white">
                {heading}
            </h2>

            {/* Authors Grid */}
            <div className="grid gap-6 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
                {authors.map((author) => {
                    const authorName = getAuthorName(author);
                    const isPersonAuthor = isPerson(author);
                    const orcid = isPersonAuthor ? author.authorable.orcid : null;

                    return (
                        <div
                            key={author.id}
                            className="flex flex-col gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm hover:shadow-md transition-shadow"
                        >
                            {/* Author Name & Type Icon */}
                            <div className="flex items-start gap-2">
                                <div className="shrink-0 mt-1">
                                    {isPersonAuthor ? (
                                        <User
                                            className="size-5 text-blue-600 dark:text-blue-400"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Building2
                                            className="size-5 text-green-600 dark:text-green-400"
                                            aria-hidden="true"
                                        />
                                    )}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <h3 className="text-lg font-medium text-gray-900 dark:text-white truncate">
                                        {authorName}
                                    </h3>
                                    {author.position && (
                                        <p className="text-sm text-gray-600 dark:text-gray-400 italic">
                                            {author.position}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* ORCID Link (for persons) */}
                            {orcid && (
                                <a
                                    href={`https://orcid.org/${orcid}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                                >
                                    <svg
                                        className="size-4"
                                        viewBox="0 0 256 256"
                                        xmlns="http://www.w3.org/2000/svg"
                                        aria-hidden="true"
                                    >
                                        <path
                                            fill="currentColor"
                                            d="M256 128c0 70.7-57.3 128-128 128S0 198.7 0 128 57.3 0 128 0s128 57.3 128 128z"
                                        />
                                        <path
                                            fill="white"
                                            d="M86.3 186.2H70.9V79.1h15.4v107.1zM108.9 79.1h41.6c39.6 0 57 28.3 57 53.6 0 27.5-21.5 53.6-56.8 53.6h-41.8V79.1zm15.4 93.3h24.5c34.9 0 42.9-26.5 42.9-39.7C191.7 111.2 178 93 148 93h-23.7v79.4zM71.3 50.8c0 5.4-4.2 9.8-9.4 9.8-5.2 0-9.4-4.3-9.4-9.8 0-5.5 4.2-9.8 9.4-9.8 5.2 0 9.4 4.3 9.4 9.8z"
                                        />
                                    </svg>
                                    <span className="truncate">{orcid}</span>
                                    <ExternalLink className="size-3 shrink-0" aria-hidden="true" />
                                </a>
                            )}

                            {/* Affiliations */}
                            {author.affiliations && author.affiliations.length > 0 && (
                                <div className="space-y-1">
                                    {author.affiliations.map((affiliation) => (
                                        <div
                                            key={affiliation.id}
                                            className="text-sm text-gray-700 dark:text-gray-300"
                                        >
                                            {affiliation.ror_id ? (
                                                <a
                                                    href={`https://ror.org/${affiliation.ror_id}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex items-center gap-1 hover:text-blue-600 dark:hover:text-blue-400 hover:underline"
                                                >
                                                    <Building2
                                                        className="size-3 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="truncate">
                                                        {affiliation.organization_name}
                                                    </span>
                                                    <ExternalLink
                                                        className="size-3 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                </a>
                                            ) : (
                                                <div className="flex items-center gap-1">
                                                    <Building2
                                                        className="size-3 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="truncate">
                                                        {affiliation.organization_name}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Roles/Badges */}
                            {author.roles && author.roles.length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                    {author.roles.map((role) => (
                                        <span
                                            key={role.id}
                                            className="inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/30 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:text-blue-300"
                                        >
                                            {role.name}
                                        </span>
                                    ))}
                                </div>
                            )}

                            {/* Email (optional) */}
                            {showEmail && author.email && (
                                <a
                                    href={`mailto:${author.email}`}
                                    className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:underline"
                                >
                                    <Mail className="size-4 shrink-0" aria-hidden="true" />
                                    <span className="truncate">{author.email}</span>
                                </a>
                            )}

                            {/* Website (optional) */}
                            {showWebsite && author.website && (
                                <a
                                    href={author.website}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:underline"
                                >
                                    <ExternalLink className="size-4 shrink-0" aria-hidden="true" />
                                    <span className="truncate">{author.website}</span>
                                </a>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* "Show more" indicator if limited */}
            {maxAuthors > 0 && resource.authors.length > maxAuthors && (
                <p className="text-sm text-gray-500 dark:text-gray-400 italic">
                    Showing {maxAuthors} of {resource.authors.length} authors
                </p>
            )}
        </section>
    );
}
