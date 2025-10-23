import { Mail } from 'lucide-react';

import AuthorsList from './AuthorsList';

// Type definition for minimal resource structure needed for ContactSection
interface ContactSectionProps {
    resource: {
        authors?: Array<{
            roles?: Array<{ slug: string; [key: string]: unknown }>;
            [key: string]: unknown;
        }>;
        [key: string]: unknown;
    };
    /** Custom heading text */
    heading?: string;
    /** Show description text */
    showDescription?: boolean;
}

/**
 * ContactSection Component
 * 
 * Displays contact persons for the dataset by filtering authors with
 * the "ContactPerson" role. Reuses AuthorsList component with
 * showEmail and showWebsite enabled.
 * 
 * Features:
 * - Automatically filters for "ContactPerson" role
 * - Shows email addresses (if available)
 * - Shows website links (if available)
 * - Optional description text
 * - Returns null if no contact persons found
 * - Responsive design with dark mode support
 */
export default function ContactSection({
    resource,
    heading = 'Contact',
    showDescription = true,
}: ContactSectionProps) {
    // Check if there are any contact persons
    const hasContactPersons = resource.authors?.some((author) =>
        author.roles?.some((role) => role.slug === 'contactperson'),
    );

    if (!hasContactPersons) {
        return null;
    }

    // Count contact persons for description
    const contactPersonsCount =
        resource.authors?.filter((author) =>
            author.roles?.some((role) => role.slug === 'contactperson'),
        ).length || 0;

    return (
        <div className="space-y-4">
            {/* Description */}
            {showDescription && (
                <div className="rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 p-4">
                    <div className="flex items-start gap-3">
                        <div className="shrink-0 mt-0.5">
                            <Mail
                                className="size-5 text-blue-600 dark:text-blue-400"
                                aria-hidden="true"
                            />
                        </div>
                        <div className="flex-1">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-1">
                                Questions about this dataset?
                            </h3>
                            <p className="text-sm text-gray-700 dark:text-gray-300">
                                {contactPersonsCount === 1
                                    ? 'Contact the person listed below for inquiries about this dataset.'
                                    : `Contact any of the ${contactPersonsCount} persons listed below for inquiries about this dataset.`}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Reuse AuthorsList with contact-specific configuration */}
            <AuthorsList
                resource={resource as never}
                filterByRole="contactperson"
                heading={heading}
                showEmail={true}
                showWebsite={true}
            />
        </div>
    );
}
