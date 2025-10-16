/**
 * AuthorList Component
 * 
 * Displays a list of author entries with drag & drop reordering support.
 * Shows an empty state when no authors are present.
 */

import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';

import type { AuthorEntry } from './types';

interface AuthorListProps {
    authors: AuthorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onChange: (authors: AuthorEntry[]) => void;
}

/**
 * AuthorList - Manages the list of authors with empty state
 */
export default function AuthorList({
    authors,
    onAdd,
    onRemove,
    onChange,
}: AuthorListProps) {
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
            {/* Author count */}
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    {authors.length} author{authors.length !== 1 ? 's' : ''}
                </p>
            </div>

            {/* Author items - TODO: Implement drag & drop */}
            <div className="space-y-4">
                {authors.map((author, index) => (
                    <div key={author.id} className="p-4 border rounded-lg">
                        <p className="text-sm">
                            Author {index + 1}: {author.type === 'person' ? `${author.firstName} ${author.lastName}` : author.institutionName}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onRemove(index)}
                            className="mt-2"
                        >
                            Remove
                        </Button>
                    </div>
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
