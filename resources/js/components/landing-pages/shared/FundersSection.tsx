import { Award, ExternalLink } from 'lucide-react';

// Type definitions for Funding References
interface FundingReference {
    id?: number;
    funder_name: string;
    funder_identifier?: string | null;
    funder_identifier_type?: string | null;
    award_number?: string | null;
    award_title?: string | null;
    award_uri?: string | null;
    [key: string]: unknown;
}

interface Resource {
    funding_references?: FundingReference[];
    [key: string]: unknown;
}

interface FundersSectionProps {
    resource: Resource;
    /** Custom heading text */
    heading?: string;
    /** Show funder identifiers */
    showIdentifiers?: boolean;
}

/**
 * FundersSection Component
 * 
 * Displays funding information for the dataset including:
 * - Funder names
 * - Award numbers
 * - Award titles (optional)
 * - Award URIs as clickable links
 * - Funder identifiers (ROR, Crossref Funder ID, etc.)
 * 
 * Features:
 * - Card-based layout with visual hierarchy
 * - Award icon badges
 * - External links for award URIs
 * - Responsive grid (1-2 columns)
 * - Dark mode support
 * - Returns null if no funding information
 */
export default function FundersSection({
    resource,
    heading = 'Funding',
    showIdentifiers = true,
}: FundersSectionProps) {
    if (!resource.funding_references || resource.funding_references.length === 0) {
        return null;
    }

    /**
     * Format funder identifier for display
     */
    const formatFunderIdentifier = (identifier: string, type?: string | null): string => {
        if (!type) return identifier;

        // Remove common prefixes for cleaner display
        const cleanIdentifier = identifier
            .replace(/^https?:\/\/(dx\.)?doi\.org\//i, '')
            .replace(/^https?:\/\/ror\.org\//i, '');

        return `${type}: ${cleanIdentifier}`;
    };

    /**
     * Get URL for funder identifier
     */
    const getFunderIdentifierUrl = (identifier: string, type?: string | null): string | null => {
        if (!identifier) return null;

        // If already a URL, return as is
        if (identifier.startsWith('http://') || identifier.startsWith('https://')) {
            return identifier;
        }

        // Build URL based on identifier type
        if (type?.toLowerCase().includes('ror')) {
            return `https://ror.org/${identifier}`;
        }

        if (type?.toLowerCase().includes('crossref') || type?.toLowerCase().includes('doi')) {
            return `https://doi.org/${identifier}`;
        }

        return null;
    };

    return (
        <section className="space-y-4" aria-label={heading}>
            {/* Heading */}
            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white">{heading}</h2>

            {/* Funders Grid */}
            <div className="grid gap-4 sm:grid-cols-1 lg:grid-cols-2">
                {resource.funding_references.map((funder, index) => {
                    const funderUrl = funder.funder_identifier
                        ? getFunderIdentifierUrl(
                              funder.funder_identifier,
                              funder.funder_identifier_type,
                          )
                        : null;

                    return (
                        <div
                            key={funder.id || index}
                            className="flex flex-col gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm"
                        >
                            {/* Funder Name */}
                            <div className="flex items-start gap-2">
                                <div className="shrink-0 mt-1">
                                    <Award
                                        className="size-5 text-yellow-600 dark:text-yellow-400"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div className="flex-1 min-w-0">
                                    {funderUrl ? (
                                        <a
                                            href={funderUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-lg font-semibold text-gray-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 hover:underline inline-flex items-center gap-1"
                                        >
                                            <span className="truncate">{funder.funder_name}</span>
                                            <ExternalLink
                                                className="size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        </a>
                                    ) : (
                                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white truncate">
                                            {funder.funder_name}
                                        </h3>
                                    )}
                                </div>
                            </div>

                            {/* Award Number */}
                            {funder.award_number && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                        Award Number
                                    </p>
                                    <p className="text-sm font-mono text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-900 px-2 py-1 rounded">
                                        {funder.award_number}
                                    </p>
                                </div>
                            )}

                            {/* Award Title */}
                            {funder.award_title && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                        Award Title
                                    </p>
                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                        {funder.award_title}
                                    </p>
                                </div>
                            )}

                            {/* Award URI */}
                            {funder.award_uri && (
                                <a
                                    href={funder.award_uri}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                                >
                                    <ExternalLink className="size-4 shrink-0" aria-hidden="true" />
                                    <span className="truncate">View Award Details</span>
                                </a>
                            )}

                            {/* Funder Identifier */}
                            {showIdentifiers &&
                                funder.funder_identifier &&
                                funder.funder_identifier_type && (
                                    <div className="pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <p className="text-xs text-gray-500 dark:text-gray-400">
                                            {formatFunderIdentifier(
                                                funder.funder_identifier,
                                                funder.funder_identifier_type,
                                            )}
                                        </p>
                                    </div>
                                )}
                        </div>
                    );
                })}
            </div>

            {/* Info text if multiple funders */}
            {resource.funding_references.length > 1 && (
                <p className="text-sm text-gray-500 dark:text-gray-400 italic">
                    This dataset was supported by {resource.funding_references.length} funding
                    sources
                </p>
            )}
        </section>
    );
}
