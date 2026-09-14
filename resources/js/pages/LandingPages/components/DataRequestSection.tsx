import { Mail } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageContactPerson } from '@/types/landing-page';

import { ContactModal } from './ContactModal';
import { LandingPageCard } from './LandingPageCard';

interface DataRequestSectionProps {
    contactPersons?: LandingPageContactPerson[];
    datasetTitle?: string;
    hasDataPublicationTeamRecipient?: boolean;
}

export const DATA_REQUEST_HEADING =
    'The dataset is not available for automated download. Please fill in the request form to receive download information.';
export const DATA_REQUEST_UNAVAILABLE_HEADING = 'The dataset is not available for automated download.';
export const DATA_REQUEST_NO_RECIPIENT_MESSAGE = 'A contact form is currently unavailable because no email recipient is available for this dataset.';

/**
 * Data request call-to-action for datasets without an automated download.
 *
 * The modal deliberately targets every available contact person and the data
 * publication team when that optional recipient is configured.
 */
export function DataRequestSection({
    contactPersons = [],
    datasetTitle = 'Dataset',
    hasDataPublicationTeamRecipient = false,
}: DataRequestSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const eligibleContactPersons = contactPersons.filter((contactPerson) => contactPerson.has_email);
    const hasRecipient = hasDataPublicationTeamRecipient || eligibleContactPersons.length > 0;

    return (
        <>
            <LandingPageCard aria-labelledby="heading-data-request" data-testid="data-request-section">
                <h2 id="heading-data-request" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {hasRecipient ? DATA_REQUEST_HEADING : DATA_REQUEST_UNAVAILABLE_HEADING}
                </h2>

                {hasRecipient ? (
                    <Button
                        onClick={() => setIsModalOpen(true)}
                        className="gfz-action-button flex w-full items-center gap-2 bg-gfz-primary text-gfz-primary-foreground hover:bg-gfz-primary/90"
                    >
                        <Mail className="h-4 w-4" aria-hidden="true" />
                        Request data via contact form
                    </Button>
                ) : (
                    <p className="text-sm text-gray-600 dark:text-gray-300">{DATA_REQUEST_NO_RECIPIENT_MESSAGE}</p>
                )}
            </LandingPageCard>

            {hasRecipient && (
                <ContactModal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                    selectedPerson={null}
                    contactPersons={eligibleContactPersons}
                    datasetTitle={datasetTitle}
                    recipientPolicy="all-contacts-and-team"
                    hasDataPublicationTeamRecipient={hasDataPublicationTeamRecipient}
                />
            )}
        </>
    );
}
