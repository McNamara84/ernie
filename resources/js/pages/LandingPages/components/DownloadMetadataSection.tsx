import { BadgeCheck, Braces, FileCode, FileJson } from 'lucide-react';

import type { LandingPageMetadataLink } from '@/types/landing-page';

import { DarkModeImage } from './DarkModeImage';

interface DownloadMetadataSectionProps {
    resourceId: number;
    jsonLdExportUrl?: string;
    metadataLinks?: LandingPageMetadataLink[];
}

/**
 * Renders canonical DataCite downloads and the selective ISO 19115-3 export.
 */
export function DownloadMetadataSection({ resourceId, jsonLdExportUrl, metadataLinks = [] }: DownloadMetadataSectionProps) {
    const urlFor = (format: LandingPageMetadataLink['format'], fallback: string): string =>
        metadataLinks.find((link) => link.format === format)?.url ?? fallback;
    const isoMetadataLink = metadataLinks.find((link) => link.format === 'iso19115-3');

    return (
        <section className="mt-6" aria-labelledby="heading-download-metadata">
            <h3 id="heading-download-metadata" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Download Metadata
            </h3>
            <div className="flex flex-wrap items-center gap-4">
                <DarkModeImage lightSrc="/images/datacite-logo.png" darkSrc="/images/datacite-logo-light.svg" alt="DataCite" className="h-8" />

                <a
                    href={urlFor('datacite-xml', `/resources/${resourceId}/export-datacite-xml`)}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as DataCite XML"
                >
                    <FileCode className="h-5 w-5" aria-hidden="true" />
                    XML
                </a>

                <a
                    href={urlFor('datacite-json', `/resources/${resourceId}/export-datacite-json`)}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as DataCite JSON"
                >
                    <FileJson className="h-5 w-5" aria-hidden="true" />
                    JSON
                </a>

                <a
                    href={urlFor('datacite-jsonld', jsonLdExportUrl ?? `/resources/${resourceId}/export-jsonld`)}
                    className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    title="Download as JSON-LD (Linked Data)"
                >
                    <Braces className="h-5 w-5" aria-hidden="true" />
                    JSON-LD
                </a>
            </div>

            {isoMetadataLink && (
                <div className="mt-4 flex flex-wrap items-center gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div
                        aria-label="ISO 19115-3 metadata available"
                        className="inline-flex items-center overflow-hidden rounded-md border border-gfz-primary bg-white text-gfz-primary dark:border-blue-300 dark:bg-gray-800 dark:text-blue-200"
                    >
                        <span className="inline-flex items-center gap-1 bg-gfz-primary px-2.5 py-1.5 text-sm font-bold tracking-wide text-white dark:bg-blue-300 dark:text-gray-900">
                            <BadgeCheck className="h-4 w-4" aria-hidden="true" />
                            ISO
                        </span>
                        <span className="px-2.5 py-1.5 text-sm font-semibold">19115-3:2023 Metadata</span>
                    </div>

                    <a
                        href={isoMetadataLink.url}
                        className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        title="Download as ISO 19115-3 XML"
                        aria-label="Download ISO 19115-3:2023 metadata as XML"
                    >
                        <FileCode className="h-5 w-5" aria-hidden="true" />
                        XML
                    </a>
                </div>
            )}
        </section>
    );
}
