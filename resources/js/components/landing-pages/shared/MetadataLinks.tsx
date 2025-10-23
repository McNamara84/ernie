import { Database, Download, FileCode2, FileJson, Globe } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { withBasePath } from '@/lib/base-path';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Metadata format configuration
 */
interface MetadataFormat {
    id: string;
    name: string;
    description: string;
    icon: React.ComponentType<{ className?: string }>;
    available: boolean;
    route?: string; // Laravel route name for functional formats
    comingSoon?: boolean; // Flag for dummy formats
}

/**
 * Resource interface for metadata export
 */
interface Resource {
    id: number;
    doi?: string | null;
}

/**
 * Props for MetadataLinks component
 */
interface MetadataLinksProps {
    resource: Resource;
    heading?: string;
    showDescriptions?: boolean;
}

// ============================================================================
// Constants
// ============================================================================

/**
 * Metadata format definitions
 * DataCite formats use existing routes, others are dummies for later implementation
 */
const METADATA_FORMATS: MetadataFormat[] = [
    {
        id: 'datacite-json',
        name: 'DataCite JSON',
        description: 'DataCite Metadata Schema v4.5+ in JSON format',
        icon: FileJson,
        available: true,
        route: 'resources.export-datacite-json',
    },
    {
        id: 'datacite-xml',
        name: 'DataCite XML',
        description: 'DataCite Metadata Schema v4.5+ in XML format',
        icon: FileCode2,
        available: true,
        route: 'resources.export-datacite-xml',
    },
    {
        id: 'iso19115',
        name: 'ISO 19115',
        description: 'Geographic information metadata standard (XML)',
        icon: Globe,
        available: false,
        comingSoon: true,
    },
    {
        id: 'schema-org',
        name: 'Schema.org',
        description: 'Structured data for web search engines (JSON-LD)',
        icon: Database,
        available: false,
        comingSoon: true,
    },
];

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Generate download URL for available formats
 */
function getDownloadUrl(format: MetadataFormat, resourceId: number): string | null {
    if (!format.available || !format.route) {
        return null;
    }

    // Use withBasePath for Laravel routes (supports subdirectory deployment)
    return withBasePath(`/resources/${resourceId}/export-datacite-${format.id.replace('datacite-', '')}`);
}

/**
 * Get badge color classes based on format availability
 */
function getBadgeColor(available: boolean): string {
    if (available) {
        return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
    }
    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
}

/**
 * Get badge text based on format availability
 */
function getBadgeText(format: MetadataFormat): string {
    if (format.available) {
        return 'Available';
    }
    if (format.comingSoon) {
        return 'Coming Soon';
    }
    return 'Not Available';
}

// ============================================================================
// Component
// ============================================================================

/**
 * MetadataLinks Component
 *
 * Displays download links for various metadata export formats.
 * - DataCite JSON/XML use existing export routes
 * - ISO19115 and Schema.org are disabled placeholders for future implementation
 * - Shows format descriptions and availability badges
 * - Accessible with proper ARIA labels
 * - Supports dark mode
 *
 * @example
 * ```tsx
 * <MetadataLinks
 *   resource={resource}
 *   heading="Export Metadata"
 *   showDescriptions={true}
 * />
 * ```
 */
export default function MetadataLinks({
    resource,
    heading = 'Metadata Export',
    showDescriptions = true,
}: MetadataLinksProps) {
    return (
        <div className="space-y-4">
            {/* Heading */}
            <h2 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h2>

            {/* Format Grid */}
            <div className="grid gap-4 sm:grid-cols-2">
                {METADATA_FORMATS.map((format) => {
                    const Icon = format.icon;
                    const downloadUrl = getDownloadUrl(format, resource.id);

                    return (
                        <div
                            key={format.id}
                            className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        >
                            {/* Format Header */}
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <Icon
                                        className="size-5 text-gray-600 dark:text-gray-400"
                                        aria-hidden="true"
                                    />
                                    <h3 className="font-medium text-gray-900 dark:text-gray-100">
                                        {format.name}
                                    </h3>
                                </div>

                                {/* Availability Badge */}
                                <span
                                    className={`rounded-full px-2 py-1 text-xs font-medium ${getBadgeColor(format.available)}`}
                                    aria-label={`Status: ${getBadgeText(format)}`}
                                >
                                    {getBadgeText(format)}
                                </span>
                            </div>

                            {/* Format Description */}
                            {showDescriptions && (
                                <p className="text-sm text-gray-600 dark:text-gray-400">
                                    {format.description}
                                </p>
                            )}

                            {/* Download Button */}
                            {downloadUrl ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                >
                                    <a
                                        href={downloadUrl}
                                        download
                                        aria-label={`Download ${format.name}`}
                                    >
                                        <Download className="mr-2 size-4" aria-hidden="true" />
                                        Download
                                    </a>
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled
                                    className="w-full"
                                    aria-label={`${format.name} not available yet`}
                                >
                                    <Download className="mr-2 size-4" aria-hidden="true" />
                                    {format.comingSoon ? 'Coming Soon' : 'Not Available'}
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Help Text */}
            <p className="text-sm text-gray-600 dark:text-gray-400">
                Download metadata in various formats for use in other systems and applications.
                DataCite formats are available now, additional formats will be added in future updates.
            </p>
        </div>
    );
}
