/**
 * AuthorField Component (Main)
 * 
 * Main component for managing authors in the DataCite form.
 * Coordinates between list, items, quick-add, and CSV import.
 */

import React from 'react';

import type { AffiliationSuggestion } from '@/types/affiliations';

import AuthorList from './author-list';
import type { AuthorEntry, AuthorType, InstitutionAuthorEntry, PersonAuthorEntry } from './types';
import { MAX_AUTHORS } from './types';

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
export default function AuthorField({
    authors,
    onChange,
    affiliationSuggestions,
}: AuthorFieldProps) {
    const handleAdd = (type: AuthorType = 'person') => {
        if (authors.length >= MAX_AUTHORS) return;
        
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

    const handleBulkAdd = (newAuthors: AuthorEntry[]) => {
        // Check if adding would exceed max limit
        const totalAfterAdd = authors.length + newAuthors.length;
        
        if (totalAfterAdd > MAX_AUTHORS) {
            const remaining = MAX_AUTHORS - authors.length;
            if (remaining > 0) {
                alert(`Only ${remaining} of ${newAuthors.length} authors can be added (Maximum: ${MAX_AUTHORS})`);
                onChange([...authors, ...newAuthors.slice(0, remaining)]);
            } else {
                alert(`Maximum of ${MAX_AUTHORS} authors already reached.`);
            }
        } else {
            onChange([...authors, ...newAuthors]);
        }
    };

    return (
        <div className="space-y-4">
            {/* Author List or Empty State */}
            <AuthorList
                authors={authors}
                onAdd={() => handleAdd('person')}
                onRemove={handleRemove}
                onAuthorChange={handleAuthorChange}
                onBulkAdd={handleBulkAdd}
                affiliationSuggestions={affiliationSuggestions}
            />

            {/* Max limit info */}
            {authors.length > 0 && authors.length >= MAX_AUTHORS && (
                <p className="text-sm text-muted-foreground text-center">
                    Maximum number of authors ({MAX_AUTHORS}) reached.
                </p>
            )}
        </div>
    );
}

// Re-export types for convenience
export type {
    AuthorEntry,
    AuthorType,
    InstitutionAuthorEntry,
    PersonAuthorEntry,
} from './types';
