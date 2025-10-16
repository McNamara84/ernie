/**
 * ContributorItem Component
 * 
 * Individual contributor entry with all fields.
 * Supports both person and institution types with roles.
 */

import React from 'react';

import type { ContributorEntry } from './types';

interface ContributorItemProps {
    contributor: ContributorEntry;
    index: number;
    onChange: (contributor: ContributorEntry) => void;
    onRemove: () => void;
}

/**
 * ContributorItem - Single contributor entry component
 * TODO: Implement full fields from contributor-field.tsx
 */
export default function ContributorItem({
    contributor,
    index,
    onChange,
    onRemove,
}: ContributorItemProps) {
    return (
        <section className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md">
            <h3 className="text-lg font-semibold">
                Contributor {index + 1}
            </h3>
            
            <div className="mt-4 space-y-4">
                <p className="text-sm text-muted-foreground">
                    Type: {contributor.type}
                </p>
                
                {contributor.type === 'person' ? (
                    <div>
                        <p>Name: {contributor.firstName} {contributor.lastName}</p>
                        <p>ORCID: {contributor.orcid || 'N/A'}</p>
                    </div>
                ) : (
                    <div>
                        <p>Institution: {contributor.institutionName}</p>
                    </div>
                )}
                
                {/* TODO: Implement all fields */}
            </div>
        </section>
    );
}
