import { useState, useCallback } from 'react';
import { Mail, User, Send } from 'lucide-react';

import { ContactModal } from './ContactModal';

interface ContactPerson {
    id: number;
    name: string;
    email: string | null;
    affiliations: string[];
}

interface ContactSectionProps {
    contactPersons: ContactPerson[];
    resourceId: number;
}

/**
 * Contact Information Section for Landing Pages
 * 
 * Displays contact persons and allows visitors to send messages
 * without exposing email addresses.
 */
export function ContactSection({ contactPersons, resourceId }: ContactSectionProps) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedContactPerson, setSelectedContactPerson] = useState<ContactPerson | null>(null);

    // Filter to only contact persons with email addresses
    const contactablePersons = contactPersons.filter((cp) => cp.email !== null);

    const handleContactClick = useCallback((contactPerson: ContactPerson) => {
        setSelectedContactPerson(contactPerson);
        setIsModalOpen(true);
    }, []);

    const handleSendToAll = useCallback(() => {
        setSelectedContactPerson(null);
        setIsModalOpen(true);
    }, []);

    const handleCloseModal = useCallback(() => {
        setIsModalOpen(false);
        setSelectedContactPerson(null);
    }, []);

    // Don't render if no contact persons with email
    if (contactablePersons.length === 0) {
        return null;
    }

    return (
        <>
            <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h2 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-900">
                    <Mail className="h-5 w-5 text-blue-600" />
                    Contact Information
                </h2>

                <p className="mb-4 text-sm text-gray-600">
                    For questions about this dataset, please contact:
                </p>

                <ul className="mb-4 space-y-3">
                    {contactablePersons.map((person) => (
                        <li key={person.id}>
                            <button
                                type="button"
                                onClick={() => handleContactClick(person)}
                                className="group flex w-full items-start gap-3 rounded-md p-2 text-left transition-colors hover:bg-blue-50"
                            >
                                <User className="mt-0.5 h-5 w-5 flex-shrink-0 text-gray-400 group-hover:text-blue-600" />
                                <div>
                                    <span className="font-medium text-gray-900 group-hover:text-blue-600">
                                        {person.name}
                                    </span>
                                    {person.affiliations.length > 0 && (
                                        <p className="text-sm text-gray-500">
                                            {person.affiliations.join(', ')}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-blue-600 opacity-0 transition-opacity group-hover:opacity-100">
                                        Click to send a message
                                    </p>
                                </div>
                            </button>
                        </li>
                    ))}
                </ul>

                {contactablePersons.length > 1 && (
                    <button
                        type="button"
                        onClick={handleSendToAll}
                        className="flex w-full items-center justify-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-100"
                    >
                        <Send className="h-4 w-4" />
                        Send message to all contact persons
                    </button>
                )}
            </div>

            <ContactModal
                isOpen={isModalOpen}
                onClose={handleCloseModal}
                resourceId={resourceId}
                contactPersons={contactablePersons}
                selectedContactPerson={selectedContactPerson}
            />
        </>
    );
}
