/**
 * ContributorField Component (Main)
 *
 * Main component for managing contributors in the DataCite form.
 * Coordinates between list, items, quick-add, and CSV import.
 */

import type { AffiliationSuggestion } from '@/types/affiliations';

import ContributorList from './contributor-list';
import type { ContributorEntry, ContributorType, InstitutionContributorEntry, PersonContributorEntry } from './types';

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
    email: '',
    website: '',
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
    affiliationSuggestions,
    personRoleOptions,
    institutionRoleOptions,
}: ContributorFieldProps) {
    const handleAdd = (type: ContributorType = 'person') => {
        const newContributor = createEmptyContributor(type);
        onChange([...contributors, newContributor]);
    };

    const handleRemove = (index: number) => {
        const updated = contributors.filter((_, i) => i !== index);
        onChange(updated);
    };

    const handleContributorChange = (index: number, contributor: ContributorEntry) => {
        const updated = contributors.map((c, i) => (i === index ? contributor : c));
        onChange(updated);
    };

    const handleReorder = (reorderedContributors: ContributorEntry[]) => {
        onChange(reorderedContributors);
    };

    const handleBulkAdd = (newContributors: ContributorEntry[]) => {
        onChange([...contributors, ...newContributors]);
    };

    return (
        <div className="space-y-4">
            {/* Contributor List or Empty State */}
            <ContributorList
                contributors={contributors}
                onAdd={() => handleAdd('person')}
                onRemove={handleRemove}
                onContributorChange={handleContributorChange}
                onReorder={handleReorder}
                onBulkAdd={handleBulkAdd}
                affiliationSuggestions={affiliationSuggestions}
                personRoleOptions={personRoleOptions}
                institutionRoleOptions={institutionRoleOptions}
            />
        </div>
    );
}

// Re-export types for convenience
export type { ContributorEntry, ContributorRoleTag, ContributorType, InstitutionContributorEntry, PersonContributorEntry } from './types';
