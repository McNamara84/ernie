import { ExternalLink, Link2 } from 'lucide-react';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Related identifier entry
 */
interface RelatedIdentifier {
    id?: number;
    identifier: string;
    identifier_type: string; // 'DOI', 'URL', 'Handle', etc.
    relation_type: string; // 'Cites', 'References', 'IsDerivedFrom', etc.
    position?: number;
    related_title?: string;
    related_metadata?: Record<string, unknown>;
}

/**
 * Resource shape for RelatedWork
 */
interface Resource {
    related_identifiers?: RelatedIdentifier[];
}

/**
 * Props for RelatedWork component
 */
interface RelatedWorkProps {
    resource: Resource;
    heading?: string;
    priorityTypes?: string[]; // Order to display relation types
    maxPerType?: number; // Max items to show per relation type (0 = unlimited)
}

// ============================================================================
// Constants
// ============================================================================

/**
 * Default priority order based on usage statistics (Top 4)
 * - Cites: 56.1%
 * - References: 14.7%
 * - IsDerivedFrom: 12.6%
 * - IsDocumentedBy: 5.2%
 */
const DEFAULT_PRIORITY_TYPES = ['Cites', 'References', 'IsDerivedFrom', 'IsDocumentedBy'];

/**
 * Human-readable labels for relation types
 */
const RELATION_TYPE_LABELS: Record<string, string> = {
    Cites: 'Citations',
    IsCitedBy: 'Cited By',
    References: 'References',
    IsReferencedBy: 'Referenced By',
    IsDerivedFrom: 'Derived From',
    IsSourceOf: 'Source Of',
    IsDocumentedBy: 'Documentation',
    Documents: 'Documents',
    IsSupplementTo: 'Supplements',
    IsSupplementedBy: 'Supplemented By',
    Compiles: 'Compiles',
    IsCompiledBy: 'Compiled By',
    HasPart: 'Has Part',
    IsPartOf: 'Part Of',
    Continues: 'Continues',
    IsContinuedBy: 'Continued By',
    Obsoletes: 'Obsoletes',
    IsObsoletedBy: 'Obsoleted By',
    IsNewVersionOf: 'New Version Of',
    IsPreviousVersionOf: 'Previous Version Of',
    HasVersion: 'Has Version',
    IsVersionOf: 'Version Of',
    IsVariantFormOf: 'Variant Form Of',
    IsOriginalFormOf: 'Original Form Of',
    IsIdenticalTo: 'Identical To',
    Describes: 'Describes',
    IsDescribedBy: 'Described By',
    HasMetadata: 'Has Metadata',
    IsMetadataFor: 'Metadata For',
    Requires: 'Requires',
    IsRequiredBy: 'Required By',
    Reviews: 'Reviews',
    IsReviewedBy: 'Reviewed By',
    IsPublishedIn: 'Published In',
    Collects: 'Collects',
    IsCollectedBy: 'Collected By',
};

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Build URL for identifier based on type
 */
function buildIdentifierUrl(identifier: string, identifierType: string): string {
    const cleanIdentifier = identifier.trim();

    switch (identifierType.toUpperCase()) {
        case 'DOI': {
            // Remove common DOI prefixes
            const doiId = cleanIdentifier
                .replace(/^https?:\/\/(dx\.)?doi\.org\//i, '')
                .replace(/^doi:/i, '');
            return `https://doi.org/${doiId}`;
        }

        case 'URL':
        case 'PURL':
        case 'W3ID':
            // Use as-is if already a URL
            return cleanIdentifier.startsWith('http') ? cleanIdentifier : `https://${cleanIdentifier}`;

        case 'HANDLE': {
            const handleId = cleanIdentifier.replace(/^https?:\/\/hdl\.handle\.net\//i, '');
            return `https://hdl.handle.net/${handleId}`;
        }

        case 'ARK': {
            const arkId = cleanIdentifier.replace(/^ark:\//i, '');
            return `https://n2t.net/ark:/${arkId}`;
        }

        case 'URN':
            return `https://nbn-resolving.org/${cleanIdentifier}`;

        case 'ARXIV': {
            const arxivId = cleanIdentifier.replace(/^arxiv:/i, '');
            return `https://arxiv.org/abs/${arxivId}`;
        }

        case 'PMID':
            return `https://pubmed.ncbi.nlm.nih.gov/${cleanIdentifier}`;

        case 'ISBN':
            return `https://www.worldcat.org/isbn/${cleanIdentifier}`;

        case 'ISSN':
        case 'EISSN':
        case 'LISSN':
            return `https://portal.issn.org/resource/ISSN/${cleanIdentifier}`;

        default:
            // If identifier looks like a URL, use it
            if (cleanIdentifier.startsWith('http')) {
                return cleanIdentifier;
            }
            // Otherwise, return empty string (no link)
            return '';
    }
}

/**
 * Get icon color based on relation type category
 */
function getRelationColor(relationType: string): string {
    // Citation types (Cites, References, IsCitedBy, etc.)
    if (['Cites', 'IsCitedBy', 'References', 'IsReferencedBy'].includes(relationType)) {
        return 'text-blue-600 dark:text-blue-400';
    }
    // Documentation types
    if (['Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy'].includes(relationType)) {
        return 'text-green-600 dark:text-green-400';
    }
    // Derivation types
    if (['IsDerivedFrom', 'IsSourceOf'].includes(relationType)) {
        return 'text-orange-600 dark:text-orange-400';
    }
    // Default
    return 'text-gray-600 dark:text-gray-400';
}

/**
 * Group related identifiers by relation type
 */
function groupByRelationType(
    relatedIdentifiers: RelatedIdentifier[],
): Record<string, RelatedIdentifier[]> {
    const groups: Record<string, RelatedIdentifier[]> = {};

    relatedIdentifiers.forEach((item) => {
        const type = item.relation_type;
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(item);
    });

    return groups;
}

// ============================================================================
// Main Component
// ============================================================================

/**
 * RelatedWork displays related identifiers grouped by relation type
 * with priority ordering for most commonly used types (Cites, References, etc.)
 */
export default function RelatedWork({
    resource,
    heading = 'Related Work',
    priorityTypes = DEFAULT_PRIORITY_TYPES,
    maxPerType = 0,
}: RelatedWorkProps) {
    const relatedIdentifiers = resource.related_identifiers || [];

    // Don't render if no related identifiers
    if (relatedIdentifiers.length === 0) {
        return null;
    }

    // Group by relation type
    const grouped = groupByRelationType(relatedIdentifiers);

    // Sort groups by priority
    const relationTypes = Object.keys(grouped).sort((a, b) => {
        const aIndex = priorityTypes.indexOf(a);
        const bIndex = priorityTypes.indexOf(b);

        // Both in priority list: sort by priority
        if (aIndex !== -1 && bIndex !== -1) {
            return aIndex - bIndex;
        }
        // Only a in priority list: a comes first
        if (aIndex !== -1) return -1;
        // Only b in priority list: b comes first
        if (bIndex !== -1) return 1;
        // Neither in priority list: sort alphabetically
        return a.localeCompare(b);
    });

    return (
        <section className="space-y-6" aria-label={heading}>
            {/* Heading */}
            <div className="flex items-center gap-2">
                <Link2 className="h-5 w-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* Relation Type Groups */}
            {relationTypes.map((relationType) => {
                const items = grouped[relationType];
                const displayItems = maxPerType > 0 ? items.slice(0, maxPerType) : items;
                const hasMore = maxPerType > 0 && items.length > maxPerType;

                return (
                    <div key={relationType} className="space-y-3">
                        {/* Subheading */}
                        <h3 className="flex items-center gap-2 text-lg font-medium text-gray-800 dark:text-gray-200">
                            <Link2
                                className={`h-4 w-4 ${getRelationColor(relationType)}`}
                                aria-hidden="true"
                            />
                            {RELATION_TYPE_LABELS[relationType] || relationType}
                            <span className="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400">
                                ({items.length})
                            </span>
                        </h3>

                        {/* Items List */}
                        <ul className="space-y-2">
                            {displayItems.map((item, index) => {
                                const url = buildIdentifierUrl(item.identifier, item.identifier_type);
                                const hasUrl = url !== '';

                                return (
                                    <li
                                        key={item.id || `${relationType}-${index}`}
                                        className="flex items-start gap-3 rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
                                    >
                                        <ExternalLink
                                            className="mt-0.5 h-4 w-4 shrink-0 text-gray-400"
                                            aria-hidden="true"
                                        />
                                        <div className="flex-1 space-y-1">
                                            {/* Title (if available) */}
                                            {item.related_title && (
                                                <div className="font-medium text-gray-900 dark:text-gray-100">
                                                    {item.related_title}
                                                </div>
                                            )}

                                            {/* Identifier */}
                                            <div className="flex items-center gap-2">
                                                <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                    {item.identifier_type}
                                                </span>
                                                {hasUrl ? (
                                                    <a
                                                        href={url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="font-mono text-sm text-blue-600 hover:underline dark:text-blue-400"
                                                    >
                                                        {item.identifier}
                                                    </a>
                                                ) : (
                                                    <span className="font-mono text-sm text-gray-600 dark:text-gray-400">
                                                        {item.identifier}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>

                        {/* Show more indicator */}
                        {hasMore && (
                            <div className="text-sm text-gray-500 dark:text-gray-400">
                                Showing {displayItems.length} of {items.length} {relationType.toLowerCase()} items
                            </div>
                        )}
                    </div>
                );
            })}
        </section>
    );
}
