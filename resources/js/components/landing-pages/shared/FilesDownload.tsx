import { Download, ExternalLink, FileArchive, Shield } from 'lucide-react';

import { Button } from '@/components/ui/button';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * License entry
 */
interface License {
    id?: number;
    identifier: string;
    name: string;
    spdx_id?: string | null;
    reference?: string | null;
    details_url?: string | null;
    is_osi_approved?: boolean;
    is_fsf_libre?: boolean;
}

/**
 * Resource shape for FilesDownload
 */
interface Resource {
    licenses?: License[];
    doi?: string | null;
}

/**
 * Landing page config with FTP URL
 */
interface LandingPageConfig {
    ftp_url?: string | null;
}

/**
 * Props for FilesDownload component
 */
interface FilesDownloadProps {
    resource: Resource;
    config: LandingPageConfig;
    heading?: string;
    showLicenseDetails?: boolean;
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get license badge color based on license type
 */
function getLicenseBadgeColor(license: License): string {
    // Open source licenses (OSI approved or FSF Libre)
    if (license.is_osi_approved || license.is_fsf_libre) {
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    }

    // Creative Commons licenses
    if (license.identifier.startsWith('CC-')) {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    }

    // Other licenses
    return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200';
}

/**
 * Get short license name for display
 */
function getShortLicenseName(license: License): string {
    // Use SPDX ID if available (e.g., "CC-BY-4.0")
    if (license.spdx_id) {
        return license.spdx_id;
    }

    // Use identifier (e.g., "CC BY 4.0")
    return license.identifier;
}

/**
 * Build FTP URL from DOI if not provided in config
 */
function buildFtpUrl(config: LandingPageConfig, resource: Resource): string | null {
    // Use configured FTP URL if available
    if (config.ftp_url) {
        return config.ftp_url;
    }

    // Try to build from DOI
    if (resource.doi) {
        const doiSuffix = resource.doi.replace(/^10\.\d+\//, '');
        return `https://datapub.gfz-potsdam.de/download/${doiSuffix}`;
    }

    return null;
}

// ============================================================================
// Main Component
// ============================================================================

/**
 * FilesDownload displays download button with FTP link and license information
 */
export default function FilesDownload({
    resource,
    config,
    heading = 'Download Dataset',
    showLicenseDetails = true,
}: FilesDownloadProps) {
    const ftpUrl = buildFtpUrl(config, resource);
    const licenses = resource.licenses || [];

    // Don't render if no FTP URL
    if (!ftpUrl) {
        return null;
    }

    return (
        <section className="space-y-4" aria-label={heading}>
            {/* Heading */}
            <div className="flex items-center gap-2">
                <FileArchive
                    className="h-5 w-5 text-indigo-600 dark:text-indigo-400"
                    aria-hidden="true"
                />
                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* Download Card */}
            <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                {/* Download Button */}
                <div className="mb-4">
                    <Button
                        asChild
                        size="lg"
                        className="w-full bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                    >
                        <a
                            href={ftpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center justify-center gap-2"
                        >
                            <Download className="h-5 w-5" aria-hidden="true" />
                            <span>Download Files</span>
                            <ExternalLink className="h-4 w-4" aria-hidden="true" />
                        </a>
                    </Button>
                </div>

                {/* FTP URL Display */}
                <div className="mb-4 rounded bg-gray-50 p-3 dark:bg-gray-900">
                    <div className="text-xs font-medium text-gray-500 dark:text-gray-400">
                        Download URL:
                    </div>
                    <a
                        href={ftpUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="break-all text-sm text-blue-600 hover:underline dark:text-blue-400"
                    >
                        {ftpUrl}
                    </a>
                </div>

                {/* License Information */}
                {licenses.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <Shield
                                className="h-4 w-4 text-gray-600 dark:text-gray-400"
                                aria-hidden="true"
                            />
                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                License{licenses.length > 1 ? 's' : ''}:
                            </span>
                        </div>

                        {/* License Badges */}
                        <div className="flex flex-wrap gap-2">
                            {licenses.map((license, index) => (
                                <div key={license.id || index} className="inline-flex flex-col gap-1">
                                    {license.details_url || license.reference ? (
                                        <a
                                            href={license.details_url || license.reference || undefined}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ${getLicenseBadgeColor(license)} hover:opacity-80`}
                                        >
                                            {getShortLicenseName(license)}
                                            <ExternalLink className="h-3 w-3" aria-hidden="true" />
                                        </a>
                                    ) : (
                                        <span
                                            className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${getLicenseBadgeColor(license)}`}
                                        >
                                            {getShortLicenseName(license)}
                                        </span>
                                    )}

                                    {showLicenseDetails && (
                                        <div className="ml-3 flex gap-2 text-xs">
                                            {license.is_osi_approved && (
                                                <span
                                                    className="text-green-600 dark:text-green-400"
                                                    title="OSI Approved"
                                                >
                                                    OSI ✓
                                                </span>
                                            )}
                                            {license.is_fsf_libre && (
                                                <span
                                                    className="text-green-600 dark:text-green-400"
                                                    title="FSF Libre"
                                                >
                                                    FSF ✓
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>

                        {/* License Full Names */}
                        {showLicenseDetails && licenses.length > 0 && (
                            <div className="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                {licenses.map((license, index) => (
                                    <div key={license.id || `name-${index}`}>
                                        <strong>{getShortLicenseName(license)}:</strong> {license.name}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Download Info */}
                <div className="mt-4 rounded-md bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                    <p>
                        <strong>Note:</strong> By downloading this dataset, you agree to comply with the
                        license terms{licenses.length > 0 ? ' listed above' : ''}.
                    </p>
                </div>
            </div>
        </section>
    );
}
