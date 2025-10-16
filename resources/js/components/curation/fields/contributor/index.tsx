/**
 * ContributorField Component (Main)
 * 
 * Main component for managing contributors in the DataCite form.
 * Coordinates between list, items, quick-add, and CSV import.
 */

import React from 'react';

import type { AffiliationSuggestion } from '@/types/affiliations';

import ContributorList from './contributor-list';
import type {
    ContributorEntry,
    ContributorType,
    InstitutionContributorEntry,
    PersonContributorEntry,
} from './types';
import { MAX_CONTRIBUTORS } from './types';

interface ContributorFieldProps {
    contributors: ContributorEntry[];
    onChange: (contributors: ContributorEntry[]) => void;
    affiliationSuggestions: AffiliationSuggestion[];
    personRoleOptions: readonly string[];
    institutionRoleOptions: readonly string[];
}

/**
 * Creates an empty person contributor entry
 */
const createEmptyPersonContributor = (): PersonContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    roles: [],
    rolesInput: '',
    affiliations: [],
    affiliationsInput: '',
    orcidVerified: false,
});

/**
 * Creates an empty institution contributor entry
 */
const createEmptyInstitutionContributor = (): InstitutionContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    roles: [],
    rolesInput: '',
    affiliations: [],
    affiliationsInput: '',
});

/**
 * Creates an empty contributor entry based on type
 */
const createEmptyContributor = (type: ContributorType = 'person'): ContributorEntry => {
    return type === 'person' ? createEmptyPersonContributor() : createEmptyInstitutionContributor();
};

/**
 * ContributorField - Main component
 */
export default function ContributorField({
    contributors,
    onChange,
}: ContributorFieldProps) {
    const handleAdd = (type: ContributorType = 'person') => {
        if (contributors.length >= MAX_CONTRIBUTORS) return;
        
        const newContributor = createEmptyContributor(type);
        onChange([...contributors, newContributor]);
    };

    const handleRemove = (index: number) => {
        const updated = contributors.filter((_, i) => i !== index);
        onChange(updated);
    };

    return (
        <div className="space-y-4">
            {/* Contributor List or Empty State */}
            <ContributorList
                contributors={contributors}
                onAdd={() => handleAdd('person')}
                onRemove={handleRemove}
                onChange={onChange}
            />

            {/* Max limit info */}
            {contributors.length > 0 && contributors.length >= MAX_CONTRIBUTORS && (
                <p className="text-sm text-muted-foreground text-center">
                    Maximum number of contributors ({MAX_CONTRIBUTORS}) reached.
                </p>
            )}
        </div>
    );
}

// Re-export types for convenience
export type {
    ContributorEntry,
    ContributorType,
    InstitutionContributorEntry,
    PersonContributorEntry,
} from './types';
