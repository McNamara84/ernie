/**
 * AuthorItem Component
 * 
 * Individual author entry with all fields.
 * Supports both person and institution types.
 */

import React from 'react';

import type { AuthorEntry } from './types';

interface AuthorItemProps {
    author: AuthorEntry;
    index: number;
    onChange: (author: AuthorEntry) => void;
    onRemove: () => void;
}

/**
 * AuthorItem - Single author entry component
 * TODO: Implement full fields from author-field.tsx
 */
export default function AuthorItem({
    author,
    index,
    onChange,
    onRemove,
}: AuthorItemProps) {
    return (
        <section className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md">
            <h3 className="text-lg font-semibold">
                Author {index + 1}
            </h3>
            
            <div className="mt-4 space-y-4">
                <p className="text-sm text-muted-foreground">
                    Type: {author.type}
                </p>
                
                {author.type === 'person' ? (
                    <div>
                        <p>Name: {author.firstName} {author.lastName}</p>
                        <p>ORCID: {author.orcid || 'N/A'}</p>
                    </div>
                ) : (
                    <div>
                        <p>Institution: {author.institutionName}</p>
                    </div>
                )}
                
                {/* TODO: Implement all fields */}
            </div>
        </section>
    );
}
