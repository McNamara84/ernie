import { Download, ExternalLink, Mail } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageContactPerson, LandingPageLicense, LandingPageLink } from '@/types/landing-page';

import { ContactModal } from './ContactModal';
import { CreativeCommonsIcon, getCreativeCommonsShortName } from './CreativeCommonsIcon';
import { LandingPageCard } from './LandingPageCard';

interface FilesSectionProps {
    downloadUrl?: string | null;
    trackedDownloadUrl?: string | null;
    downloadLabel?: string | null;
    downloadFiles?: { url: string; label?: string | null; tracked_url?: string | null }[];
    licenses: LandingPageLicense[];
    contactPersons?: LandingPageContactPerson[];
    datasetTitle?: string;
    additionalLinks?: LandingPageLink[];
}

/**
 * Fallback display mode when no download URL is available.
 * Priority order (highest to lowest):
 * 1. 'download' - Direct download link (ftp_url configured)
 * 2. 'contact-form' - Contact form button (contact person with email exists)
 * 3. 'website' - External website link (contact person with website but no email)
 * 4. 'fallback-message' - Generic message (no contact options available)
 */
type FallbackMode = 'download' | 'contact-form' | 'website' | 'fallback-message';

const DEFAULT_PRIMARY_DOWNLOAD_LABEL = 'Download data and description';

export function FilesSection({
    downloadUrl,
    trackedDownloadUrl,
    downloadLabel,
    downloadFiles,
    licenses,
    contactPersons = [],
    datasetTitle,
    additionalLinks = [],
}: FilesSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedPerson, setSelectedPerson] = useState<LandingPageContactPerson | null>(null);

    // Build effective list of download URLs: prefer downloadFiles, fall back to single downloadUrl
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
                        href: trackedDownloadUrl ?? downloadUrl!,
                        url: downloadUrl!,
                        label: downloadLabel?.trim() || DEFAULT_PRIMARY_DOWNLOAD_LABEL,
                    },
                ]
              : [];

    // Find contact persons for fallback options
    const contactPersonWithEmail = contactPersons.find((p) => p.has_email);
    const contactPersonWithWebsite = contactPersons.find((p) => p.website && p.website.trim() !== '');

    /**
     * Determine which UI element to display based on available contact options.
     * The priority is explicit and intentional:
     * - Download URL takes precedence over all fallbacks
     * - Contact form (email) is preferred over website link for better UX
     * - Website link is a last resort before the fallback message
     */
    const displayMode: FallbackMode =
        effectiveDownloads.length > 0
            ? 'download'
            : contactPersonWithEmail
              ? 'contact-form'
              : contactPersonWithWebsite
                ? 'website'
                : 'fallback-message';

    const handleContactClick = (person: LandingPageContactPerson) => {
        setSelectedPerson(person);
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        setSelectedPerson(null);
    };

    return (
        <>
            <LandingPageCard aria-labelledby="heading-files" data-testid="files-section">
                <h2 id="heading-files" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Files
                </h2>

                <div className="space-y-3">
                    {/* Download Link(s) - shown when download URLs are available */}
                    {displayMode === 'download' && effectiveDownloads.length === 1 && (
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
                    {displayMode === 'download' && effectiveDownloads.length > 1 && (
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
                                    <Download className="h-4 w-4" aria-hidden="true" />
                                    <span className="truncate">{download.label}</span>
                                </a>
                            ))}
                        </div>
                    )}

                    {/* Contact Form Button - shown when no download URL but contact person with email exists */}
                    {displayMode === 'contact-form' && contactPersonWithEmail && (
                        <Button
                            onClick={() => handleContactClick(contactPersonWithEmail)}
                            className="gfz-action-button flex w-full items-center gap-2 bg-gfz-primary text-gfz-primary-foreground hover:bg-gfz-primary/90"
                        >
                            <Mail className="h-4 w-4" aria-hidden="true" />
                            Request data via contact form
                        </Button>
                    )}

                    {/* Website Link - shown when no download URL and no contact email, but contact person has website */}
                    {displayMode === 'website' && contactPersonWithWebsite && (
                        <a
                            href={contactPersonWithWebsite.website!}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="gfz-action-button flex items-center gap-2 rounded-lg bg-gfz-primary px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                        >
                            <ExternalLink className="h-4 w-4" aria-hidden="true" />
                            Visit contact person website
                        </a>
                    )}

                    {/* No download available message - when no contact options are available */}
                    {displayMode === 'fallback-message' && (
                        <p className="text-sm text-gray-500 italic dark:text-gray-400">
                            Download information not available. Please contact the authors for data access.
                        </p>
                    )}

                    {/* Additional Links - displayed below primary download action, styled in light grey */}
                    {displayMode === 'download' && additionalLinks.length > 0 && (
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

                    {/* License Badges */}
                    {licenses.length > 0 && (
                        <div className="mt-4 space-y-2">
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">License</p>
                            <div className="flex flex-col gap-2">
                                {licenses.map((license) => {
                                    const title = license.spdx_id ?? license.name;
                                    const shortName = license.spdx_id ? getCreativeCommonsShortName(license.spdx_id) : null;
                                    const showShortName = shortName !== null && license.name.trim().toUpperCase() !== shortName.toUpperCase();
                                    const icon = license.spdx_id ? (
                                        <CreativeCommonsIcon spdxId={license.spdx_id} />
                                    ) : license.reference ? (
                                        <ExternalLink className="h-4 w-4" aria-hidden="true" />
                                    ) : null;
                                    const badgeContent = (
                                        <>
                                            {icon}
                                            <span className="min-w-0 break-words">
                                                {license.name}
                                                {showShortName && ` (${shortName})`}
                                            </span>
                                        </>
                                    );

                                    if (!license.reference) {
                                        return (
                                            <span
                                                key={license.id}
                                                data-testid="license-badge"
                                                className="inline-flex items-center gap-2 rounded-md bg-green-100 px-3 py-2 text-sm font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300"
                                                title={title}
                                            >
                                                {badgeContent}
                                            </span>
                                        );
                                    }

                                    return (
                                        <a
                                            key={license.id}
                                            href={license.reference}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            data-testid="license-badge"
                                            className="inline-flex items-center gap-2 rounded-md bg-green-100 px-3 py-2 text-sm font-medium text-green-800 transition-colors hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-900/50"
                                            title={title}
                                        >
                                            {badgeContent}
                                        </a>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </LandingPageCard>

            {/* Contact Modal - rendered when user clicks contact button */}
            {selectedPerson && (
                <ContactModal
                    isOpen={isModalOpen}
                    onClose={handleCloseModal}
                    selectedPerson={selectedPerson}
                    contactPersons={contactPersons}
                    datasetTitle={datasetTitle || 'Dataset'}
                />
            )}
        </>
    );
}
