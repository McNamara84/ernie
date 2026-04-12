import { Braces, FileCode, FileJson } from 'lucide-react';

interface DownloadMetadataSectionProps {
    resourceId: number;
    jsonLdExportUrl?: string;
}

/**
 * Renders the DataCite metadata download buttons (XML, JSON, JSON-LD).
 */
export function DownloadMetadataSection({ resourceId, jsonLdExportUrl }: DownloadMetadataSectionProps) {
    return (
        <div className="mt-6">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Download Metadata</h3>
            <div className="flex flex-wrap items-center gap-4">
                <img src="/images/datacite-logo.png" alt="DataCite" className="h-8 dark:brightness-200 dark:invert" />

                <a
                    href={`/resources/${resourceId}/export-datacite-xml`}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as DataCite XML"
                >
                    <FileCode className="h-5 w-5" aria-hidden="true" />
                    XML
                </a>

                <a
                    href={`/resources/${resourceId}/export-datacite-json`}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as DataCite JSON"
                >
                    <FileJson className="h-5 w-5" aria-hidden="true" />
                    JSON
                </a>

                <a
                    href={jsonLdExportUrl ?? `/resources/${resourceId}/export-jsonld`}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as JSON-LD (Linked Data)"
                >
                    <Braces className="h-5 w-5" aria-hidden="true" />
                    JSON-LD
                </a>
            </div>
        </div>
    );
}
