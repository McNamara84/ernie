import { Mail } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageContactPerson } from '@/types/landing-page';

import { ContactModal } from './ContactModal';
import { LandingPageCard } from './LandingPageCard';

interface DataRequestSectionProps {
    contactPersons?: LandingPageContactPerson[];
    datasetTitle?: string;
}

export const DATA_REQUEST_HEADING =
    'The dataset is not available for automated download. Please fill in the request form to receive download information.';

/**
 * Data request call-to-action for datasets without an automated download.
 *
 * The modal deliberately targets every available contact person. When none are
 * available, the server falls back to the configured data publication team.
 */
export function DataRequestSection({ contactPersons = [], datasetTitle = 'Dataset' }: DataRequestSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);

    return (
        <>
            <LandingPageCard aria-labelledby="heading-data-request" data-testid="data-request-section">
                <h2 id="heading-data-request" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {DATA_REQUEST_HEADING}
                </h2>

                <Button
                    onClick={() => setIsModalOpen(true)}
                    className="gfz-action-button flex w-full items-center gap-2 bg-gfz-primary text-gfz-primary-foreground hover:bg-gfz-primary/90"
                >
                    <Mail className="h-4 w-4" aria-hidden="true" />
                    Request data via contact form
                </Button>
            </LandingPageCard>

            <ContactModal
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                selectedPerson={null}
                contactPersons={contactPersons}
                datasetTitle={datasetTitle}
                recipientPolicy="all-contacts-and-team"
            />
        </>
    );
}
