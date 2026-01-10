import { Download, ExternalLink, Mail } from 'lucide-react';
import { useState } from 'react';

import { ContactModal } from './ContactModal';
import { CreativeCommonsIcon } from './CreativeCommonsIcon';

interface License {
    id: number;
    name: string;
    spdx_id: string;
    reference: string;
}

interface ContactPerson {
    id: number;
    name: string;
    given_name: string | null;
    family_name: string | null;
    type: string;
    orcid: string | null;
    website: string | null;
    has_email: boolean;
}

interface FilesSectionProps {
    downloadUrl?: string | null;
    licenses: License[];
    contactPersons?: ContactPerson[];
    datasetTitle?: string;
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

export function FilesSection({ downloadUrl, licenses, contactPersons = [], datasetTitle }: FilesSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedPerson, setSelectedPerson] = useState<ContactPerson | null>(null);

    // Check if downloadUrl is a valid, non-empty URL (not just '#' or empty string)
    const hasDownloadUrl = downloadUrl && downloadUrl !== '#' && downloadUrl.trim() !== '';

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
    const displayMode: FallbackMode = hasDownloadUrl
        ? 'download'
        : contactPersonWithEmail
            ? 'contact-form'
            : contactPersonWithWebsite
                ? 'website'
                : 'fallback-message';

    const handleContactClick = (person: ContactPerson) => {
        setSelectedPerson(person);
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        setSelectedPerson(null);
    };

    return (
        <>
            <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 className="mb-4 text-lg font-semibold text-gray-900">Files</h3>

                <div className="space-y-3">
                    {/* Download Link - shown when FTP URL is configured */}
                    {displayMode === 'download' && downloadUrl && (
                        <a
                            href={downloadUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="gfz-action-button flex items-center gap-2 rounded-lg bg-[#0C2A63] px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                        >
                            <Download className="h-4 w-4" />
                            Download data and description
                        </a>
                    )}

                    {/* Contact Form Button - shown when no download URL but contact person with email exists */}
                    {displayMode === 'contact-form' && contactPersonWithEmail && (
                        <button
                            onClick={() => handleContactClick(contactPersonWithEmail)}
                            className="gfz-action-button flex w-full items-center gap-2 rounded-lg bg-[#0C2A63] px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                        >
                            <Mail className="h-4 w-4" />
                            Request data via contact form
                        </button>
                    )}

                    {/* Website Link - shown when no download URL and no contact email, but contact person has website */}
                    {displayMode === 'website' && contactPersonWithWebsite && (
                        <a
                            href={contactPersonWithWebsite.website!}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="gfz-action-button flex items-center gap-2 rounded-lg bg-[#0C2A63] px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                        >
                            <ExternalLink className="h-4 w-4" />
                            Visit contact person website
                        </a>
                    )}

                    {/* No download available message - when no contact options are available */}
                    {displayMode === 'fallback-message' && (
                        <p className="text-sm italic text-gray-500">
                            Download information not available. Please contact the authors for data access.
                        </p>
                    )}

                    {/* License Badges */}
                    {licenses.length > 0 && (
                        <div className="mt-4 space-y-2">
                            <p className="text-xs font-medium text-gray-500">License</p>
                            <div className="flex flex-col gap-2">
                                {licenses.map((license) => (
                                    <a
                                        key={license.id}
                                        href={license.reference}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 rounded-md bg-green-100 px-3 py-2 text-sm font-medium text-green-800 transition-colors hover:bg-green-200"
                                        title={`SPDX: ${license.spdx_id}`}
                                    >
                                        <CreativeCommonsIcon spdxId={license.spdx_id} className="h-4 w-4" />
                                        <span>{license.name}</span>
                                    </a>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

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
