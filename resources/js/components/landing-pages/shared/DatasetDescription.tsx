import { FileText } from 'lucide-react';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Description entry with type
 */
interface Description {
    id?: number;
    description_type: string; // 'Abstract', 'Methods', 'TechnicalInfo', 'Other', 'SeriesInformation', 'TableOfContents'
    description: string;
}

/**
 * Resource shape for DatasetDescription
 */
interface Resource {
    descriptions?: Description[];
}

/**
 * Props for DatasetDescription component
 */
interface DatasetDescriptionProps {
    resource: Resource;
    heading?: string;
    priorityTypes?: string[]; // Order to display description types
    expandable?: boolean; // Make long descriptions collapsible
    maxLength?: number; // Max characters before truncation (0 = no truncation)
}

// ============================================================================
// Constants
// ============================================================================

/**
 * Default priority order for description types
 */
const DEFAULT_PRIORITY_TYPES = [
    'Abstract',
    'Methods',
    'TechnicalInfo',
    'SeriesInformation',
    'TableOfContents',
    'Other',
];

/**
 * Human-readable labels for description types
 */
const DESCRIPTION_TYPE_LABELS: Record<string, string> = {
    Abstract: 'Abstract',
    Methods: 'Methods',
    TechnicalInfo: 'Technical Information',
    Other: 'Additional Information',
    SeriesInformation: 'Series Information',
    TableOfContents: 'Table of Contents',
};

/**
 * Icon colors for different description types
 */
const TYPE_COLORS: Record<string, string> = {
    Abstract: 'text-blue-600 dark:text-blue-400',
    Methods: 'text-green-600 dark:text-green-400',
    TechnicalInfo: 'text-purple-600 dark:text-purple-400',
    SeriesInformation: 'text-orange-600 dark:text-orange-400',
    TableOfContents: 'text-indigo-600 dark:text-indigo-400',
    Other: 'text-gray-600 dark:text-gray-400',
};

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get label for description type
 */
function getTypeLabel(descriptionType: string): string {
    return DESCRIPTION_TYPE_LABELS[descriptionType] || descriptionType;
}

/**
 * Get color class for description type
 */
function getTypeColor(descriptionType: string): string {
    return TYPE_COLORS[descriptionType] || TYPE_COLORS.Other;
}

/**
 * Format description text with line breaks and links preserved
 */
function formatDescription(text: string): string {
    // Preserve line breaks
    return text;
}

/**
 * Check if description text is long
 */
function isLongDescription(text: string, threshold: number): boolean {
    return threshold > 0 && text.length > threshold;
}

/**
 * Truncate text to max length with ellipsis
 */
function truncateText(text: string, maxLength: number): string {
    if (maxLength === 0 || text.length <= maxLength) {
        return text;
    }
    return text.substring(0, maxLength).trim() + '...';
}

// ============================================================================
// Main Component
// ============================================================================

/**
 * DatasetDescription displays dataset descriptions grouped by type
 * Supports Abstract, Methods, Technical Info, and other description types
 */
export default function DatasetDescription({
    resource,
    heading = 'Description',
    priorityTypes = DEFAULT_PRIORITY_TYPES,
    expandable = false,
    maxLength = 0,
}: DatasetDescriptionProps) {
    const descriptions = resource.descriptions || [];

    // Don't render if no descriptions
    if (descriptions.length === 0) {
        return null;
    }

    // Group by description type
    const grouped: Record<string, Description[]> = {};
    descriptions.forEach((desc) => {
        const type = desc.description_type;
        if (!grouped[type]) {
            grouped[type] = [];
        }
        grouped[type].push(desc);
    });

    // Sort types by priority
    const descriptionTypes = Object.keys(grouped).sort((a, b) => {
        const aIndex = priorityTypes.indexOf(a);
        const bIndex = priorityTypes.indexOf(b);

        if (aIndex !== -1 && bIndex !== -1) {
            return aIndex - bIndex;
        }
        if (aIndex !== -1) return -1;
        if (bIndex !== -1) return 1;
        return a.localeCompare(b);
    });

    return (
        <section className="space-y-6" aria-label={heading}>
            {/* Heading */}
            <div className="flex items-center gap-2">
                <FileText
                    className="h-5 w-5 text-indigo-600 dark:text-indigo-400"
                    aria-hidden="true"
                />
                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* Description Type Groups */}
            {descriptionTypes.map((descType) => {
                const items = grouped[descType];

                return (
                    <div key={descType} className="space-y-3">
                        {/* Only show type heading if there are multiple types */}
                        {descriptionTypes.length > 1 && (
                            <h3
                                className={`flex items-center gap-2 text-lg font-medium text-gray-800 dark:text-gray-200`}
                            >
                                <FileText
                                    className={`h-4 w-4 ${getTypeColor(descType)}`}
                                    aria-hidden="true"
                                />
                                {getTypeLabel(descType)}
                            </h3>
                        )}

                        {/* Description Items */}
                        {items.map((item, index) => {
                            const isLong = isLongDescription(item.description, maxLength);
                            const displayText = expandable
                                ? truncateText(item.description, maxLength)
                                : item.description;

                            return (
                                <div
                                    key={item.id || `${descType}-${index}`}
                                    className="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                                >
                                    <div className="prose prose-sm max-w-none dark:prose-invert">
                                        <p className="whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                                            {formatDescription(displayText)}
                                        </p>
                                    </div>
                                    {expandable && isLong && (
                                        <div className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                                            Text truncated. Full description available in metadata
                                            export.
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                );
            })}
        </section>
    );
}
