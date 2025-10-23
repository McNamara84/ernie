import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';

// Resource type for Landing Pages
// This will be expanded and moved to a shared types file
interface Author {
    id: number;
    authorable_type: string;
    authorable: {
        given_name?: string;
        family_name?: string;
        name?: string;
        [key: string]: unknown;
    };
    [key: string]: unknown;
}

interface Title {
    title: string;
    title_type?: string;
    [key: string]: unknown;
}

interface Publisher {
    name: string;
    [key: string]: unknown;
}

interface ResourceType {
    resource_type_general: string;
    [key: string]: unknown;
}

interface Language {
    code: string;
    name: string;
    [key: string]: unknown;
}

interface Resource {
    id: number;
    doi?: string | null;
    year?: number;
    version?: string | null;
    titles?: Title[];
    authors?: Author[];
    publisher?: Publisher;
    resource_type?: ResourceType;
    language?: Language;
    [key: string]: unknown;
}

interface CitationBoxProps {
    resource: Resource;
}

/**
 * CitationBox Component
 * 
 * Displays a formatted DataCite citation with copy-to-clipboard functionality.
 * 
 * Features:
 * - DataCite-compliant citation format
 * - Copy to clipboard button with success feedback
 * - DOI link (if available)
 * - Author list with proper formatting
 * - Publication year and publisher
 * - Resource type and version
 * - Responsive design with dark mode support
 * 
 * Citation Format (DataCite):
 * Authors (Year): Title. Publisher. Resource Type. Version. DOI
 */
export default function CitationBox({ resource }: CitationBoxProps) {
    const [copied, setCopied] = useState(false);

    /**
     * Format authors for citation
     * - Persons: "LastName, FirstName"
     * - Institutions: "Name"
     * - Multiple authors separated by "; "
     * - Limit to first 10 authors, add "et al." if more
     */
    const formatAuthors = (): string => {
        if (!resource.authors || resource.authors.length === 0) {
            return 'Unknown Author';
        }

        const MAX_AUTHORS = 10;
        const authors = resource.authors.slice(0, MAX_AUTHORS);
        
        const formatted = authors.map((author) => {
            if (author.authorable_type === 'App\\Models\\Person') {
                const person = author.authorable as { 
                    given_name?: string; 
                    family_name?: string 
                };
                const familyName = person.family_name || '';
                const givenName = person.given_name || '';
                return givenName ? `${familyName}, ${givenName}` : familyName;
            } else {
                // Institution
                const institution = author.authorable as { name?: string };
                return institution.name || 'Unknown Institution';
            }
        }).filter(Boolean);

        if (resource.authors.length > MAX_AUTHORS) {
            formatted.push('et al.');
        }

        return formatted.join('; ');
    };

    /**
     * Build complete citation string
     */
    const buildCitation = (): string => {
        const authors = formatAuthors();
        const year = resource.year || 'n.d.';
        const title = resource.titles?.[0]?.title || 'Untitled Dataset';
        const publisher = resource.publisher?.name || 'GFZ Data Services';
        const resourceType = resource.resource_type?.resource_type_general || 'Dataset';
        const version = resource.version ? ` Version ${resource.version}.` : '';
        const doi = resource.doi ? ` https://doi.org/${resource.doi}` : '';

        return `${authors} (${year}): ${title}. ${publisher}. ${resourceType}.${version}${doi}`;
    };

    /**
     * Copy citation to clipboard
     */
    const handleCopy = async () => {
        try {
            const citation = buildCitation();
            await navigator.clipboard.writeText(citation);
            
            setCopied(true);
            toast.success('Citation copied to clipboard');
            
            // Reset copied state after 2 seconds
            setTimeout(() => setCopied(false), 2000);
        } catch (error) {
            toast.error('Failed to copy citation');
            console.error('Clipboard error:', error);
        }
    };

    const citation = buildCitation();
    const doi = resource.doi;

    return (
        <section 
            className="border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 rounded-lg p-6"
            aria-label="Citation"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="flex-1 space-y-3">
                    {/* Heading */}
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                        Cite as
                    </h2>

                    {/* Citation Text */}
                    <div className="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                        <p className="wrap-break-word">
                            {citation.split('https://doi.org/')[0]}
                            {doi && (
                                <a
                                    href={`https://doi.org/${doi}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-blue-600 dark:text-blue-400 hover:underline font-medium"
                                >
                                    https://doi.org/{doi}
                                </a>
                            )}
                        </p>
                    </div>

                    {/* Additional Info */}
                    {resource.language && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                            Language: {resource.language.name}
                        </div>
                    )}
                </div>

                {/* Copy Button */}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={handleCopy}
                    disabled={copied}
                    className="shrink-0"
                    aria-label="Copy citation to clipboard"
                >
                    {copied ? (
                        <>
                            <Check className="mr-2 size-4" aria-hidden="true" />
                            Copied
                        </>
                    ) : (
                        <>
                            <Copy className="mr-2 size-4" aria-hidden="true" />
                            Copy
                        </>
                    )}
                </Button>
            </div>

            {/* DOI Badge (if no DOI exists) */}
            {!doi && (
                <div className="mt-4 pt-4 border-t border-blue-200 dark:border-blue-800">
                    <p className="text-xs text-gray-500 dark:text-gray-400 italic">
                        DOI registration pending
                    </p>
                </div>
            )}
        </section>
    );
}
