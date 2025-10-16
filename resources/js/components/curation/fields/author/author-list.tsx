/**
 * AuthorList Component
 * 
 * Displays a list of author entries with drag & drop reordering support.
 * Shows an empty state when no authors are present.
 */

import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

import AuthorItem from './author-item';
import type { AuthorEntry, AuthorType } from './types';

interface AuthorListProps {
    authors: AuthorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onAuthorChange: (index: number, author: AuthorEntry) => void;
    affiliationSuggestions: AffiliationSuggestion[];
}

/**
 * AuthorList - Manages the list of authors with empty state
 */
export default function AuthorList({
    authors,
    onAdd,
    onRemove,
    onAuthorChange,
    affiliationSuggestions,
}: AuthorListProps) {
    // Helper: Handle type change
    const handleTypeChange = (index: number, type: AuthorType) => {
        const author = authors[index];
        
        if (type === author.type) return;
        
        // Create new author entry with correct type
        const newAuthor: AuthorEntry = type === 'person' 
            ? {
                id: author.id,
                type: 'person',
                orcid: '',
                firstName: '',
                lastName: '',
                email: '',
                website: '',
                isContact: false,
                affiliations: author.affiliations,
                affiliationsInput: author.affiliationsInput,
            }
            : {
                id: author.id,
                type: 'institution',
                institutionName: '',
                affiliations: author.affiliations,
                affiliationsInput: author.affiliationsInput,
            };
        
        onAuthorChange(index, newAuthor);
    };

    // Helper: Handle person field change
    const handlePersonFieldChange = (
        index: number,
        field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website',
        value: string
    ) => {
        const author = authors[index];
        if (author.type !== 'person') return;
        
        onAuthorChange(index, {
            ...author,
            [field]: value,
        });
    };

    // Helper: Handle institution name change
    const handleInstitutionNameChange = (index: number, value: string) => {
        const author = authors[index];
        if (author.type !== 'institution') return;
        
        onAuthorChange(index, {
            ...author,
            institutionName: value,
        });
    };

    // Helper: Handle contact change
    const handleContactChange = (index: number, checked: boolean) => {
        const author = authors[index];
        if (author.type !== 'person') return;
        
        onAuthorChange(index, {
            ...author,
            isContact: checked,
        });
    };

    // Helper: Handle affiliations change
    const handleAffiliationsChange = (
        index: number,
        value: { raw: string; tags: AffiliationTag[] }
    ) => {
        const author = authors[index];
        
        onAuthorChange(index, {
            ...author,
            affiliations: value.tags,
            affiliationsInput: value.raw,
        });
    };

    // Empty state
    if (authors.length === 0) {
        return (
            <div className="text-center py-8 text-muted-foreground border-2 border-dashed rounded-lg">
                <p className="mb-4">No authors yet.</p>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onAdd}
                >
                    <Plus className="h-4 w-4 mr-2" />
                    Add First Author
                </Button>
            </div>
        );
    }

    // List with authors
    return (
        <div className="space-y-4">
            {/* Author items */}
            <div className="space-y-4">
                {authors.map((author, index) => (
                    <AuthorItem
                        key={author.id}
                        author={author}
                        index={index}
                        onTypeChange={(type) => handleTypeChange(index, type)}
                        onPersonFieldChange={(field, value) => 
                            handlePersonFieldChange(index, field, value)
                        }
                        onInstitutionNameChange={(value) => 
                            handleInstitutionNameChange(index, value)
                        }
                        onContactChange={(checked) => 
                            handleContactChange(index, checked)
                        }
                        onAffiliationsChange={(value) => 
                            handleAffiliationsChange(index, value)
                        }
                        onRemove={() => onRemove(index)}
                        canRemove={authors.length > 1}
                        affiliationSuggestions={affiliationSuggestions}
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
                    Add Author
                </Button>
            </div>
        </div>
    );
}
