import { Download, ExternalLink } from 'lucide-react';

import type { LandingPageContactPerson, LandingPageLink } from '@/types/landing-page';

import { DataRequestSection } from './DataRequestSection';
import { LandingPageCard } from './LandingPageCard';

interface FilesSectionProps {
    downloadUrl?: string | null;
    trackedDownloadUrl?: string | null;
    downloadLabel?: string | null;
    downloadFiles?: { url: string; label?: string | null; tracked_url?: string | null }[];
    contactPersons?: LandingPageContactPerson[];
    datasetTitle?: string;
    additionalLinks?: LandingPageLink[];
}

const DEFAULT_PRIMARY_DOWNLOAD_LABEL = 'Download data and description';

/**
 * Automated download actions for a landing page.
 *
 * Historical configurations may have no download without setting the explicit
 * downloads_unavailable flag. They retain the data-request fallback here.
 */
export function FilesSection({
    downloadUrl,
    trackedDownloadUrl,
    downloadLabel,
    downloadFiles,
    contactPersons = [],
    datasetTitle,
    additionalLinks = [],
}: FilesSectionProps) {
    const hasDownloadUrl = typeof downloadUrl === 'string' && downloadUrl !== '#' && downloadUrl.trim() !== '';
    const effectiveDownloads =
        downloadFiles && downloadFiles.length > 0
            ? downloadFiles.map((file, index) => ({
                  href: file.tracked_url ?? file.url,
                  url: file.url,
                  label: file.label?.trim() || (downloadFiles.length === 1 ? DEFAULT_PRIMARY_DOWNLOAD_LABEL : `Download (${index + 1})`),
              }))
            : hasDownloadUrl
              ? [
                    {
                        href: trackedDownloadUrl ?? downloadUrl,
                        url: downloadUrl,
                        label: downloadLabel?.trim() || DEFAULT_PRIMARY_DOWNLOAD_LABEL,
                    },
                ]
              : [];

    if (effectiveDownloads.length === 0) {
        return <DataRequestSection contactPersons={contactPersons} datasetTitle={datasetTitle} />;
    }

    return (
        <LandingPageCard aria-labelledby="heading-files" data-testid="files-section">
            <h2 id="heading-files" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Files
            </h2>

            <div className="space-y-3">
                {effectiveDownloads.length === 1 && (
                    <a
                        href={effectiveDownloads[0].href}
                        title={effectiveDownloads[0].url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="gfz-action-button flex items-center gap-2 rounded-lg bg-gfz-primary px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                    >
                        <Download className="h-4 w-4" aria-hidden="true" />
                        {effectiveDownloads[0].label}
                    </a>
                )}

                {effectiveDownloads.length > 1 && (
                    <div className="space-y-2">
                        {effectiveDownloads.map((download) => (
                            <a
                                key={download.url}
                                href={download.href}
                                title={download.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="gfz-action-button flex items-center gap-2 rounded-lg bg-gfz-primary px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                            >
                                <Download className="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span className="truncate">{download.label}</span>
                            </a>
                        ))}
                    </div>
                )}

                {additionalLinks.length > 0 && (
                    <div className="space-y-1.5">
                        {[...additionalLinks]
                            .sort((a, b) => a.position - b.position)
                            .map((link) => (
                                <a
                                    key={link.id ?? `${link.url}-${link.position}`}
                                    href={link.url}
                                    title={link.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                                >
                                    <ExternalLink className="h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span className="truncate">{link.label}</span>
                                </a>
                            ))}
                    </div>
                )}
            </div>
        </LandingPageCard>
    );
}
