import { ExternalLink, Mail, User } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageContactPerson } from '@/types/landing-page';

import { ContactModal } from './ContactModal';
import { LandingPageCard } from './LandingPageCard';
import { OrcidIcon, RorIcon } from './PidIcons';

interface ContactSectionProps {
    contactPersons: LandingPageContactPerson[];
    datasetTitle: string;
}

/**
 * Contact Information Section for Landing Pages
 *
 * Displays contact persons with their affiliations, ORCID links, and website links.
 * Clicking on a contact person opens a modal to send a message without exposing emails.
 *
 * The contact form URL is computed by the ContactModal from the current page path
 * by appending '/contact'. This works because landing pages follow the pattern
 * /{doi}/{slug} and the contact endpoint is at /{doi}/{slug}/contact.
 */
export function ContactSection({ contactPersons, datasetTitle }: ContactSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedPerson, setSelectedPerson] = useState<LandingPageContactPerson | null>(null);

    if (contactPersons.length === 0) {
        return null;
    }

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
            <LandingPageCard
                aria-labelledby="heading-contact"
            >
                <h2 id="heading-contact" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Contact Information</h2>

                <div className="space-y-4">
                    {contactPersons.map((person) => {
                        const displayName = person.family_name && person.given_name
                            ? `${person.family_name}, ${person.given_name}`
                            : person.name;

                        return (
                            <div key={`${person.source}-${person.id}`} className="flex flex-col gap-2 border-b border-gray-100 pb-3 last:border-b-0 last:pb-0 dark:border-gray-700">
                                {/* Name row with contact and external links */}
                                <div className="flex flex-wrap items-center gap-2">
                                    {/* Contact link — display as "Last, First" for persons */}
                                    {person.has_email && (
                                        <Button
                                            variant="link"
                                            onClick={() => handleContactClick(person)}
                                            className="h-auto gap-1.5 p-0 text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                            title={`Contact ${person.name}`}
                                        >
                                            <User className="h-4 w-4" aria-hidden="true" />
                                            {displayName}
                                        </Button>
                                    )}

                                    {/* ORCID icon */}
                                    {person.orcid && (
                                        <a
                                            href={`https://orcid.org/${person.orcid}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label={`ORCID profile of ${displayName}`}
                                            className="inline-flex min-h-11 min-w-11 items-center justify-center -m-3 p-3 transition-opacity hover:opacity-80"
                                        >
                                            <OrcidIcon />
                                        </a>
                                    )}

                                    {/* Website button */}
                                    {person.website && (
                                        <a
                                            href={person.website}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 transition-colors hover:bg-gray-200 hover:text-gray-800 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-gray-200"
                                            title="Visit website"
                                        >
                                            <ExternalLink className="h-3 w-3" aria-hidden="true" />
                                            Website
                                        </a>
                                    )}
                                </div>

                                {/* Affiliations */}
                                {person.affiliations.length > 0 && (
                                    <div className="ml-5 flex flex-wrap items-center gap-x-1 text-xs text-gray-500 dark:text-gray-400">
                                        {person.affiliations.map((aff, idx) => (
                                            <span key={idx} className="inline-flex items-center gap-0.5">
                                                {aff.name}
                                                {aff.identifier && aff.scheme === 'ROR' && (
                                                    <a
                                                        href={aff.identifier}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        aria-label={`ROR profile of ${aff.name}`}
                                                        className="inline-flex min-h-11 min-w-11 items-center justify-center -m-3 p-3 transition-opacity hover:opacity-80"
                                                    >
                                                        <RorIcon className="h-3 w-3" />
                                                    </a>
                                                )}
                                                {idx < person.affiliations.length - 1 && ','}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Send Request button — always shown */}
                <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <Button
                        onClick={() => {
                            if (contactPersons.length === 1) {
                                setSelectedPerson(contactPersons[0]);
                            } else {
                                setSelectedPerson(null);
                            }
                            setIsModalOpen(true);
                        }}
                        className="w-full bg-gfz-primary text-gfz-primary-foreground hover:bg-gfz-primary/90"
                    >
                        <Mail className="h-4 w-4" aria-hidden="true" />
                        Send Request
                    </Button>
                </div>
            </LandingPageCard>

            {/* Contact Modal */}
            <ContactModal
                isOpen={isModalOpen}
                onClose={handleCloseModal}
                selectedPerson={selectedPerson}
                contactPersons={contactPersons}
                datasetTitle={datasetTitle}
            />
        </>
    );
}
