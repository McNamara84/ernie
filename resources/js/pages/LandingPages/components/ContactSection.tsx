import { ExternalLink, Mail, User } from 'lucide-react';
import { useState } from 'react';

import { ContactModal } from './ContactModal';

interface Affiliation {
    name: string;
    identifier: string | null;
    scheme: string | null;
}

interface ContactPerson {
    id: number;
    name: string;
    given_name: string | null;
    family_name: string | null;
    type: string;
    affiliations: Affiliation[];
    orcid: string | null;
    website: string | null;
    has_email: boolean;
}

interface ContactSectionProps {
    contactPersons: ContactPerson[];
    contactUrl: string;
    datasetTitle: string;
}

/**
 * Contact Information Section for Landing Pages
 *
 * Displays contact persons with their affiliations, ORCID links, and website links.
 * Clicking on a contact person opens a modal to send a message without exposing emails.
 */
export function ContactSection({ contactPersons, contactUrl, datasetTitle }: ContactSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedPerson, setSelectedPerson] = useState<ContactPerson | null>(null);

    if (contactPersons.length === 0) {
        return null;
    }

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
                <h3 className="mb-4 text-lg font-semibold text-gray-900">Contact Information</h3>

                <div className="space-y-4">
                    {contactPersons.map((person) => (
                        <div key={person.id} className="flex flex-col gap-2 border-b border-gray-100 pb-3 last:border-b-0 last:pb-0">
                            {/* Name row with contact and external links */}
                            <div className="flex flex-wrap items-center gap-2">
                                {/* Contact link */}
                                {person.has_email && (
                                    <button
                                        onClick={() => handleContactClick(person)}
                                        className="flex items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 hover:underline"
                                        title={`Contact ${person.name}`}
                                    >
                                        <User className="h-4 w-4" />
                                        {person.name}
                                    </button>
                                )}

                                {/* ORCID icon */}
                                {person.orcid && (
                                    <a
                                        href={`https://orcid.org/${person.orcid}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title="ORCID Profile"
                                        className="flex items-center transition-opacity hover:opacity-80"
                                    >
                                        <img src="https://orcid.org/assets/vectors/orcid.logo.icon.svg" alt="ORCID" className="h-4 w-4" />
                                    </a>
                                )}

                                {/* Website button */}
                                {person.website && (
                                    <a
                                        href={person.website}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 transition-colors hover:bg-gray-200 hover:text-gray-800"
                                        title="Visit website"
                                    >
                                        <ExternalLink className="h-3 w-3" />
                                        Website
                                    </a>
                                )}
                            </div>

                            {/* Affiliations */}
                            {person.affiliations.length > 0 && (
                                <div className="ml-5 flex flex-wrap items-center gap-x-1 text-xs text-gray-500">
                                    {person.affiliations.map((aff, idx) => (
                                        <span key={idx} className="inline-flex items-center gap-0.5">
                                            {aff.name}
                                            {aff.identifier && aff.scheme === 'ROR' && (
                                                <a
                                                    href={aff.identifier}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="ROR Profile"
                                                    className="inline-flex items-center transition-opacity hover:opacity-80"
                                                >
                                                    <img
                                                        src="https://raw.githubusercontent.com/ror-community/ror-logos/main/ror-icon-rgb.svg"
                                                        alt="ROR"
                                                        className="h-3 w-3"
                                                    />
                                                </a>
                                            )}
                                            {idx < person.affiliations.length - 1 && ','}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {/* Contact all button if multiple contact persons */}
                {contactPersons.length > 1 && (
                    <div className="mt-4 border-t border-gray-200 pt-4">
                        <button
                            onClick={() => {
                                setSelectedPerson(null);
                                setIsModalOpen(true);
                            }}
                            className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
                            style={{ backgroundColor: '#0C2A63' }}
                        >
                            <Mail className="h-4 w-4" />
                            Contact all ({contactPersons.length})
                        </button>
                    </div>
                )}
            </div>

            {/* Contact Modal */}
            <ContactModal
                isOpen={isModalOpen}
                onClose={handleCloseModal}
                selectedPerson={selectedPerson}
                contactPersons={contactPersons}
                contactUrl={contactUrl}
                datasetTitle={datasetTitle}
            />
        </>
    );
}
