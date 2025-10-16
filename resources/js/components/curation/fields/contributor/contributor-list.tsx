/**
 * ContributorList Component
 * 
 * Displays a list of contributor entries with drag & drop reordering support.
 * Shows an empty state when no contributors are present.
 */

import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import ContributorItem from './contributor-item';
import type { ContributorEntry, ContributorRoleTag, ContributorType } from './types';

interface ContributorListProps {
    contributors: ContributorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onContributorChange: (index: number, contributor: ContributorEntry) => void;
    affiliationSuggestions: AffiliationSuggestion[];
    personRoleOptions: readonly string[];
    institutionRoleOptions: readonly string[];
}

/**
 * ContributorList - Manages the list of contributors with empty state
 */
export default function ContributorList({
    contributors,
    onAdd,
    onRemove,
    onContributorChange,
    affiliationSuggestions,
    personRoleOptions,
    institutionRoleOptions,
}: ContributorListProps) {
    // Helper: Handle type change
    const handleTypeChange = (index: number, type: ContributorType) => {
        const contributor = contributors[index];
        
        if (type === contributor.type) return;
        
        // Create new contributor entry with correct type
        const newContributor: ContributorEntry = type === 'person' 
            ? {
                id: contributor.id,
                type: 'person',
                orcid: '',
                firstName: '',
                lastName: '',
                roles: contributor.roles,
                rolesInput: contributor.rolesInput,
                affiliations: contributor.affiliations,
                affiliationsInput: contributor.affiliationsInput,
            }
            : {
                id: contributor.id,
                type: 'institution',
                institutionName: '',
                roles: contributor.roles,
                rolesInput: contributor.rolesInput,
                affiliations: contributor.affiliations,
                affiliationsInput: contributor.affiliationsInput,
            };
        
        onContributorChange(index, newContributor);
    };

    // Helper: Handle roles change
    const handleRolesChange = (
        index: number,
        value: { raw: string; tags: ContributorRoleTag[] }
    ) => {
        const contributor = contributors[index];
        
        onContributorChange(index, {
            ...contributor,
            roles: value.tags,
            rolesInput: value.raw,
        });
    };

    // Helper: Handle person field change
    const handlePersonFieldChange = (
        index: number,
        field: 'orcid' | 'firstName' | 'lastName',
        value: string
    ) => {
        const contributor = contributors[index];
        if (contributor.type !== 'person') return;
        
        onContributorChange(index, {
            ...contributor,
            [field]: value,
        });
    };

    // Helper: Handle institution name change
    const handleInstitutionNameChange = (index: number, value: string) => {
        const contributor = contributors[index];
        if (contributor.type !== 'institution') return;
        
        onContributorChange(index, {
            ...contributor,
            institutionName: value,
        });
    };

    // Helper: Handle affiliations change
    const handleAffiliationsChange = (
        index: number,
        value: { raw: string; tags: AffiliationTag[] }
    ) => {
        const contributor = contributors[index];
        
        onContributorChange(index, {
            ...contributor,
            affiliations: value.tags,
            affiliationsInput: value.raw,
        });
    };

    // Empty state
    if (contributors.length === 0) {
        return (
            <div className="text-center py-8 text-muted-foreground border-2 border-dashed rounded-lg">
                <p className="mb-4">No contributors yet.</p>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onAdd}
                >
                    <Plus className="h-4 w-4 mr-2" />
                    Add First Contributor
                </Button>
            </div>
        );
    }

    // List with contributors
    return (
        <div className="space-y-4">
            {/* Contributor items */}
            <div className="space-y-4">
                {contributors.map((contributor, index) => (
                    <ContributorItem
                        key={contributor.id}
                        contributor={contributor}
                        index={index}
                        onTypeChange={(type) => handleTypeChange(index, type)}
                        onRolesChange={(value) => handleRolesChange(index, value)}
                        onPersonFieldChange={(field, value) => 
                            handlePersonFieldChange(index, field, value)
                        }
                        onInstitutionNameChange={(value) => 
                            handleInstitutionNameChange(index, value)
                        }
                        onAffiliationsChange={(value) => 
                            handleAffiliationsChange(index, value)
                        }
                        onContributorChange={(updatedContributor) => onContributorChange(index, updatedContributor)}
                        onRemove={() => onRemove(index)}
                        canRemove={contributors.length > 1}
                        affiliationSuggestions={affiliationSuggestions}
                        personRoleOptions={personRoleOptions}
                        institutionRoleOptions={institutionRoleOptions}
                    />
                ))}
            </div>

            {/* Add button */}
            <div className="flex justify-center">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onAdd}
                >
                    <Plus className="h-4 w-4 mr-2" />
                    Add Contributor
                </Button>
            </div>
        </div>
    );
}
