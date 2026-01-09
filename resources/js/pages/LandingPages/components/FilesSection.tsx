import { useState } from 'react';

import { Download, ExternalLink, Mail } from 'lucide-react';

import { ContactModal } from './ContactModal';

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

export function FilesSection({ downloadUrl, licenses, contactPersons = [], datasetTitle }: FilesSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedPerson, setSelectedPerson] = useState<ContactPerson | null>(null);

    // Check if downloadUrl is a valid, non-empty URL (not just '#' or empty string)
    const hasDownloadUrl = downloadUrl && downloadUrl !== '#' && downloadUrl.trim() !== '';

    // Find a contact person with email (for contact form fallback)
    const contactPersonWithEmail = contactPersons.find((p) => p.has_email);

    // Find a contact person with website (for website link fallback)
    const contactPersonWithWebsite = contactPersons.find((p) => p.website && p.website.trim() !== '');

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

                    {/* Contact Form Button - shown when no download URL but contact person with email exists */}
                    {!hasDownloadUrl && contactPersonWithEmail && (
                        <button
                            onClick={() => handleContactClick(contactPersonWithEmail)}
                            className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                            style={{ backgroundColor: '#0C2A63' }}
                        >
                            <Mail className="h-4 w-4" />
                            Request data via contact form
                        </button>
                    )}

                    {/* Website Link - shown when no download URL, no contact email, but contact person has website */}
                    {!hasDownloadUrl && !contactPersonWithEmail && contactPersonWithWebsite && (
                        <a
                            href={contactPersonWithWebsite.website!}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                            style={{ backgroundColor: '#0C2A63' }}
                        >
                            <ExternalLink className="h-4 w-4" />
                            Visit contact person website
                        </a>
                    )}

                    {/* No download available message - when neither download URL nor contact person available */}
                    {!hasDownloadUrl && !contactPersonWithEmail && !contactPersonWithWebsite && (
                        <p className="text-sm italic text-gray-500">
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
