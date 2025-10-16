/**
 * ContributorList Component
 * 
 * Displays a list of contributor entries with drag & drop reordering support.
 * Shows an empty state when no contributors are present.
 */

import { Plus, Upload } from 'lucide-react';
import React, { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import ContributorCsvImport from '../contributor-csv-import';
import type { ParsedContributor } from '../contributor-csv-import';
import ContributorItem from './contributor-item';
import type { ContributorEntry, ContributorRoleTag, ContributorType } from './types';

interface ContributorListProps {
    contributors: ContributorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onContributorChange: (index: number, contributor: ContributorEntry) => void;
    onBulkAdd?: (contributors: ContributorEntry[]) => void;
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
    onBulkAdd,
    affiliationSuggestions,
    personRoleOptions,
    institutionRoleOptions,
}: ContributorListProps) {
    // State for CSV import dialog
    const [csvDialogOpen, setCsvDialogOpen] = useState(false);

    // Helper: Convert CSV parsed contributor to ContributorEntry
    const convertParsedContributorToEntry = (parsed: ParsedContributor): ContributorEntry => {
        const id = `contributor-${Date.now()}-${Math.random().toString(36).substring(7)}`;
        
        if (parsed.type === 'institution') {
            return {
                id,
                type: 'institution',
                institutionName: parsed.institutionName || '',
                roles: parsed.contributorRole 
                    ? [{ value: parsed.contributorRole }]
                    : [],
                rolesInput: parsed.contributorRole || '',
                affiliations: parsed.affiliations.map((name: string) => ({
                    id: `aff-${Date.now()}-${Math.random()}`,
                    value: name,
                    rorId: null,
                })),
                affiliationsInput: parsed.affiliations.join(', '),
            };
        }
        
        // Person type
        return {
            id,
            type: 'person',
            orcid: parsed.orcid || '',
            firstName: parsed.firstName || '',
            lastName: parsed.lastName || '',
            orcidVerified: false,
            roles: parsed.contributorRole 
                ? [{ value: parsed.contributorRole }]
                : [],
            rolesInput: parsed.contributorRole || '',
            affiliations: parsed.affiliations.map((name: string) => ({
                id: `aff-${Date.now()}-${Math.random()}`,
                value: name,
                rorId: null,
            })),
            affiliationsInput: parsed.affiliations.join(', '),
        };
    };

    // Helper: Handle CSV import
    const handleCsvImport = (parsedContributors: ParsedContributor[]) => {
        const newContributors = parsedContributors.map(convertParsedContributorToEntry);
        if (onBulkAdd) {
            onBulkAdd(newContributors);
        }
        // Close dialog immediately after import
        setCsvDialogOpen(false);
    };

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
            <div className="text-center py-8 text-muted-foreground border-2 border-dashed rounded-lg" role="status">
                <p className="mb-4">No contributors yet.</p>
                <div className="flex justify-center gap-2">
                    <Button 
                        type="button" 
                        variant="outline" 
                        onClick={onAdd}
                        aria-label="Add first contributor"
                    >
                        <Plus className="h-4 w-4 mr-2" aria-hidden="true" />
                        Add First Contributor
                    </Button>
                    <Dialog open={csvDialogOpen} onOpenChange={setCsvDialogOpen}>
                        <DialogTrigger asChild>
                            <Button 
                                type="button" 
                                variant="outline"
                                aria-label="Import contributors from CSV file"
                            >
                                <Upload className="h-4 w-4 mr-2" aria-hidden="true" />
                                Import CSV
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl max-h-[90vh]">
                            <DialogHeader>
                                <DialogTitle>Import Contributors from CSV</DialogTitle>
                                <DialogDescription>
                                    Upload a CSV file to add multiple contributors at once
                                </DialogDescription>
                            </DialogHeader>
                            <ContributorCsvImport
                                onImport={handleCsvImport}
                                onClose={() => setCsvDialogOpen(false)}
                            />
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        );
    }

    // List with contributors
    return (
        <div className="space-y-4">
            {/* Contributor items */}
            <div className="space-y-4" role="list" aria-label="Contributors">
                {contributors.map((contributor, index) => (
                    <div key={contributor.id} role="listitem">
                        <ContributorItem
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
                    </div>
                ))}
            </div>

            {/* Add button */}
            <div className="flex justify-center gap-2">
                <Button 
                    type="button" 
                    variant="outline" 
                    onClick={onAdd}
                    aria-label="Add another contributor"
                >
                    <Plus className="h-4 w-4 mr-2" aria-hidden="true" />
                    Add Contributor
                </Button>
                <Dialog open={csvDialogOpen} onOpenChange={setCsvDialogOpen}>
                    <DialogTrigger asChild>
                        <Button 
                            type="button" 
                            variant="outline"
                            aria-label="Import contributors from CSV file"
                        >
                            <Upload className="h-4 w-4 mr-2" aria-hidden="true" />
                            Import CSV
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-2xl max-h-[90vh]">
                        <DialogHeader>
                            <DialogTitle>Import Contributors from CSV</DialogTitle>
                            <DialogDescription>
                                Upload a CSV file to add multiple contributors at once
                            </DialogDescription>
                        </DialogHeader>
                        <ContributorCsvImport
                            onImport={handleCsvImport}
                            onClose={() => setCsvDialogOpen(false)}
                        />
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
