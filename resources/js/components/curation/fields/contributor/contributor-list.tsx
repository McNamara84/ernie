/**
 * ContributorList Component
 * 
 * Displays a list of contributor entries with drag & drop reordering support.
 * Shows an empty state when no contributors are present.
 */

import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';

import type { ContributorEntry } from './types';

interface ContributorListProps {
    contributors: ContributorEntry[];
    onAdd: () => void;
    onRemove: (index: number) => void;
    onChange: (contributors: ContributorEntry[]) => void;
}

/**
 * ContributorList - Manages the list of contributors with empty state
 */
export default function ContributorList({
    contributors,
    onAdd,
    onRemove,
    onChange,
}: ContributorListProps) {
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
            {/* Contributor count */}
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    {contributors.length} contributor{contributors.length !== 1 ? 's' : ''}
                </p>
            </div>

            {/* Contributor items - TODO: Implement drag & drop */}
            <div className="space-y-4">
                {contributors.map((contributor, index) => (
                    <div key={contributor.id} className="p-4 border rounded-lg">
                        <p className="text-sm">
                            Contributor {index + 1}: {contributor.type === 'person' ? `${contributor.firstName} ${contributor.lastName}` : contributor.institutionName}
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
                    Add Contributor
                </Button>
            </div>
        </div>
    );
}
