/**
 * AuthorList Component
 * 
 * Displays a list of author entries with drag & drop reordering support.
 * Shows an empty state when no authors are present.
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

import AuthorCsvImport from '../author-csv-import';
import type { ParsedAuthor } from '../author-csv-import';
import AuthorItem from './author-item';
import type { AuthorEntry, AuthorType } from './types';

interface AuthorListProps {
    authors: AuthorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onAuthorChange: (index: number, author: AuthorEntry) => void;
    onBulkAdd?: (authors: AuthorEntry[]) => void;
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
    onBulkAdd,
    affiliationSuggestions,
}: AuthorListProps) {
    // State for CSV import dialog
    const [csvDialogOpen, setCsvDialogOpen] = useState(false);

    // Helper: Convert CSV row to AuthorEntry
    const convertParsedAuthorToEntry = (parsed: ParsedAuthor): AuthorEntry => {
        const id = `author-${Date.now()}-${Math.random().toString(36).substring(7)}`;
        
        if (parsed.type === 'institution') {
            return {
                id,
                type: 'institution',
                institutionName: parsed.institutionName || '',
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
            email: parsed.email || '',
            website: '',
            isContact: parsed.isContact || false,
            orcidVerified: false,
            affiliations: parsed.affiliations.map((name: string) => ({
                id: `aff-${Date.now()}-${Math.random()}`,
                value: name,
                rorId: null,
            })),
            affiliationsInput: parsed.affiliations.join(', '),
        };
    };

    // Helper: Handle CSV import
    const handleCsvImport = (parsedAuthors: ParsedAuthor[]) => {
        const newAuthors = parsedAuthors.map(convertParsedAuthorToEntry);
        if (onBulkAdd) {
            onBulkAdd(newAuthors);
        }
        // Close dialog immediately after import
        setCsvDialogOpen(false);
    };

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
                <div className="flex justify-center gap-2">
                    <Button type="button" variant="outline" onClick={onAdd}>
                        <Plus className="h-4 w-4 mr-2" />
                        Add First Author
                    </Button>
                    <Dialog open={csvDialogOpen} onOpenChange={setCsvDialogOpen}>
                        <DialogTrigger asChild>
                            <Button type="button" variant="outline">
                                <Upload className="h-4 w-4 mr-2" />
                                Import CSV
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl max-h-[90vh]">
                            <DialogHeader>
                                <DialogTitle>Import Authors from CSV</DialogTitle>
                                <DialogDescription>
                                    Upload a CSV file to add multiple authors at once
                                </DialogDescription>
                            </DialogHeader>
                            <AuthorCsvImport
                                onImport={handleCsvImport}
                                onClose={() => setCsvDialogOpen(false)}
                            />
                        </DialogContent>
                    </Dialog>
                </div>
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
                        onAuthorChange={(updatedAuthor) => onAuthorChange(index, updatedAuthor)}
                        onRemove={() => onRemove(index)}
                        canRemove={authors.length > 1}
                        affiliationSuggestions={affiliationSuggestions}
                    />
                ))}
            </div>

            {/* Add button */}
            <div className="flex justify-center gap-2">
                <Button type="button" variant="outline" onClick={onAdd}>
                    <Plus className="h-4 w-4 mr-2" />
                    Add Author
                </Button>
                <Dialog open={csvDialogOpen} onOpenChange={setCsvDialogOpen}>
                    <DialogTrigger asChild>
                        <Button type="button" variant="outline">
                            <Upload className="h-4 w-4 mr-2" />
                            Import CSV
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-2xl max-h-[90vh]">
                        <DialogHeader>
                            <DialogTitle>Import Authors from CSV</DialogTitle>
                            <DialogDescription>
                                Upload a CSV file to add multiple authors at once
                            </DialogDescription>
                        </DialogHeader>
                        <AuthorCsvImport
                            onImport={handleCsvImport}
                            onClose={() => setCsvDialogOpen(false)}
                        />
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
