import { Mail } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageIgsnMetadata, LandingPageRepositoryContact } from '@/types/landing-page';

import { ContactModal } from './ContactModal';
import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface RepositoriesSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    datasetTitle: string;
}

// Keep the address token and boundary rules aligned with IgsnRepositoryContactService.
const LEGACY_EMAIL_PATTERN = /(?:^|[^A-Z0-9._%+@-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?=$|[^A-Z0-9._%+@-])/i;

function legacyContactDescriptor(type: 'current' | 'original', value: string | null | undefined): LandingPageRepositoryContact | null {
    const contact = value?.trim();
    if (!contact) return null;

    const containsAddressLikeValue = contact.includes('@');
    return {
        type,
        label: containsAddressLikeValue ? `${type === 'current' ? 'Current' : 'Original'} repository contact` : contact,
        has_email: LEGACY_EMAIL_PATTERN.test(contact),
    };
}

export function RepositoriesSection({ igsn, datasetTitle }: RepositoriesSectionProps): ReactNode {
    const [selectedContact, setSelectedContact] = useState<LandingPageRepositoryContact | null>(null);
    const contacts = igsn?.repository_contacts ?? [];
    const currentContact =
        contacts.find((contact) => contact.type === 'current') ?? legacyContactDescriptor('current', igsn?.current_archive_contact);
    const originalContact =
        contacts.find((contact) => contact.type === 'original') ?? legacyContactDescriptor('original', igsn?.original_archive_contact);
    const contactValue = (contact: LandingPageRepositoryContact | null): ReactNode => {
        if (!contact) return null;

        return (
            <div className="flex flex-wrap items-center gap-2">
                <span>{contact.label}</span>
                {contact.has_email && (
                    <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={() => setSelectedContact(contact)}>
                        <Mail className="h-4 w-4" aria-hidden="true" />
                        Contact {contact.type} repository
                    </Button>
                )}
            </div>
        );
    };

    const rows: MetadataRow[] = [
        { label: 'Current Repository', value: igsn?.current_archive ?? null },
        { label: 'Current Repository Contact', value: contactValue(currentContact) },
        { label: 'Original Repository', value: igsn?.original_archive ?? null },
        { label: 'Original Repository Contact', value: contactValue(originalContact) },
        { label: 'Sample Access', value: igsn?.sample_access ?? null },
    ];

    if (!hasVisibleMetadataRows(rows)) {
        return null;
    }

    return (
        <>
            <LandingPageCard aria-labelledby="heading-repositories">
                <h2 id="heading-repositories" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Repositories
                </h2>
                <MetadataList rows={rows} />
            </LandingPageCard>
            <ContactModal
                isOpen={selectedContact !== null}
                onClose={() => setSelectedContact(null)}
                selectedPerson={null}
                contactPersons={[]}
                datasetTitle={datasetTitle}
                repositoryContact={selectedContact}
            />
        </>
    );
}
