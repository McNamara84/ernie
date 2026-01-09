import { Download, Mail } from 'lucide-react';

interface License {
    id: number;
    name: string;
    spdx_id: string;
    reference: string;
}

interface FilesSectionProps {
    downloadUrl?: string | null;
    licenses: License[];
    contactUrl?: string;
    datasetTitle?: string;
}

export function FilesSection({ downloadUrl, licenses, contactUrl, datasetTitle }: FilesSectionProps) {
    // Check if downloadUrl is a valid, non-empty URL (not just '#' or empty string)
    const hasDownloadUrl = downloadUrl && downloadUrl !== '#' && downloadUrl.trim() !== '';

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">Files</h3>

            <div className="space-y-3">
                {/* Download Link - only shown if FTP URL is configured */}
                {hasDownloadUrl && (
                    <a
                        href={downloadUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                        style={{ backgroundColor: '#0C2A63' }}
                    >
                        <Download className="h-4 w-4" />
                        Download data and description
                    </a>
                )}

                {/* Contact Form Link - shown when no download URL is available */}
                {!hasDownloadUrl && contactUrl && (
                    <a
                        href={`${contactUrl}${datasetTitle ? `?subject=${encodeURIComponent(`Data request: ${datasetTitle}`)}` : ''}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700"
                    >
                        <Mail className="h-4 w-4" />
                        Request data via contact form
                    </a>
                )}

                {/* No download available message - when neither download URL nor contact URL is available */}
                {!hasDownloadUrl && !contactUrl && (
                    <p className="text-sm text-gray-500 italic">
                        Download information not available. Please contact the authors for data access.
                    </p>
                )}

                {/* License Badges */}
                {licenses.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {licenses.map((license) => (
                            <a
                                key={license.id}
                                href={license.reference}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 transition-colors hover:bg-green-200"
                            >
                                {license.name}
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
