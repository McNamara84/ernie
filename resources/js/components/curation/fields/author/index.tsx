/**
 * AuthorField Component (Main)
 *
 * Main component for managing authors in the DataCite form.
 * Coordinates between list, items, quick-add, and CSV import.
 */

import type { AffiliationSuggestion } from '@/types/affiliations';

import AuthorList from './author-list';
import type { AuthorEntry, AuthorType, InstitutionAuthorEntry, PersonAuthorEntry } from './types';

interface AuthorFieldProps {
    authors: AuthorEntry[];
    onChange: (authors: AuthorEntry[]) => void;
    affiliationSuggestions: AffiliationSuggestion[];
}

/**
 * Creates an empty person author entry
 */
const createEmptyPersonAuthor = (): PersonAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    email: '',
    website: '',
    isContact: false,
    affiliations: [],
    affiliationsInput: '',
    orcidVerified: false,
});

/**
 * Creates an empty institution author entry
 */
const createEmptyInstitutionAuthor = (): InstitutionAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

/**
 * Creates an empty author entry based on type
 */
const createEmptyAuthor = (type: AuthorType = 'person'): AuthorEntry => {
    return type === 'person' ? createEmptyPersonAuthor() : createEmptyInstitutionAuthor();
};

/**
 * AuthorField - Main component
 */
export default function AuthorField({ authors, onChange, affiliationSuggestions }: AuthorFieldProps) {
    const handleAdd = (type: AuthorType = 'person') => {
        const newAuthor = createEmptyAuthor(type);
        onChange([...authors, newAuthor]);
    };

    const handleRemove = (index: number) => {
        const updated = authors.filter((_, i) => i !== index);
        onChange(updated);
    };

    const handleAuthorChange = (index: number, author: AuthorEntry) => {
        const updated = authors.map((a, i) => (i === index ? author : a));
        onChange(updated);
    };

    const handleReorder = (reorderedAuthors: AuthorEntry[]) => {
        onChange(reorderedAuthors);
    };

    const handleBulkAdd = (newAuthors: AuthorEntry[]) => {
        onChange([...authors, ...newAuthors]);
    };

    return (
        <div className="space-y-4">
            {/* Author List or Empty State */}
            <AuthorList
                authors={authors}
                onAdd={() => handleAdd('person')}
                onRemove={handleRemove}
                onAuthorChange={handleAuthorChange}
                onReorder={handleReorder}
                onBulkAdd={handleBulkAdd}
                affiliationSuggestions={affiliationSuggestions}
            />
        </div>
    );
}

// Re-export types for convenience
export type { AuthorEntry, AuthorType, InstitutionAuthorEntry, PersonAuthorEntry } from './types';
