import { Tag } from 'lucide-react';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Free keyword entry
 */
interface FreeKeyword {
    id?: number;
    keyword: string;
}

/**
 * Controlled keyword entry (GCMD or MSL)
 */
interface ControlledKeyword {
    id?: number;
    keyword_id?: number;
    text: string;
    path?: string;
    language?: string;
    scheme: string; // 'gcmd:sciencekeywords' | 'gcmd:platforms' | 'gcmd:instruments' | 'msl'
    scheme_uri?: string;
}

/**
 * Resource shape for KeywordsSection
 */
interface Resource {
    keywords?: FreeKeyword[];
    controlled_keywords?: ControlledKeyword[];
}

/**
 * Props for KeywordsSection component
 */
interface KeywordsSectionProps {
    resource: Resource;
    heading?: string;
    showPaths?: boolean; // Show hierarchical paths for GCMD keywords
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get display color for keyword scheme
 */
function getSchemeColor(scheme: string): string {
    if (scheme.startsWith('gcmd:')) {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    }
    if (scheme === 'msl') {
        return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
    }
    return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
}

/**
 * Get scheme label for display
 */
function getSchemeLabel(scheme: string): string {
    const labels: Record<string, string> = {
        'gcmd:sciencekeywords': 'GCMD Science',
        'gcmd:platforms': 'GCMD Platform',
        'gcmd:instruments': 'GCMD Instrument',
        msl: 'MSL',
    };
    return labels[scheme] || scheme.toUpperCase();
}

/**
 * Format hierarchical path for display
 */
function formatPath(path: string | undefined): string {
    if (!path) return '';
    // Replace separators with readable format
    return path.replace(/>/g, ' › ').replace(/\|/g, ' | ');
}

// ============================================================================
// Main Component
// ============================================================================

/**
 * KeywordsSection displays free and controlled keywords with visual distinction
 * between different vocabularies (GCMD Science Keywords, GCMD Platforms, MSL)
 */
export default function KeywordsSection({
    resource,
    heading = 'Keywords',
    showPaths = true,
}: KeywordsSectionProps) {
    const freeKeywords = resource.keywords || [];
    const controlledKeywords = resource.controlled_keywords || [];

    // Don't render if no keywords at all
    if (freeKeywords.length === 0 && controlledKeywords.length === 0) {
        return null;
    }

    return (
        <section className="space-y-4" aria-label={heading}>
            {/* Heading */}
            <div className="flex items-center gap-2">
                <Tag className="h-5 w-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* Free Keywords */}
            {freeKeywords.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Free Keywords
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {freeKeywords.map((kw, index) => (
                            <span
                                key={kw.id || `free-${index}`}
                                className="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200"
                            >
                                {kw.keyword}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* Controlled Keywords */}
            {controlledKeywords.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Controlled Keywords
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {controlledKeywords.map((kw, index) => (
                            <div
                                key={kw.id || `controlled-${index}`}
                                className="inline-flex flex-col gap-1"
                            >
                                <span
                                    className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm ${getSchemeColor(kw.scheme)}`}
                                >
                                    <span className="text-xs font-semibold">
                                        {getSchemeLabel(kw.scheme)}
                                    </span>
                                    <span className="border-l border-current pl-2">
                                        {kw.text}
                                    </span>
                                </span>
                                {showPaths && kw.path && (
                                    <span className="ml-3 text-xs text-gray-500 dark:text-gray-400">
                                        {formatPath(kw.path)}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Info Text */}
            {controlledKeywords.length > 0 && (
                <div className="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
                    <p>
                        <strong>Controlled vocabularies:</strong> Keywords from standardized
                        vocabularies like GCMD (NASA Global Change Master Directory) and MSL
                        (Materials Science and Engineering) ensure consistent metadata across
                        repositories.
                    </p>
                </div>
            )}
        </section>
    );
}
