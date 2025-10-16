/**
 * AuthorQuickAdd Component
 * 
 * Quick-add form for adding authors with minimal fields.
 * Extended details can be edited in the full item view.
 */

import React from 'react';

import type { AuthorEntry, AuthorType } from './types';

interface AuthorQuickAddProps {
    onAdd: (author: Omit<AuthorEntry, 'id'>) => void;
}

/**
 * AuthorQuickAdd - Compact form for quick author entry
 * TODO: Implement actual form
 */
export default function AuthorQuickAdd({ onAdd }: AuthorQuickAddProps) {
    return (
        <div className="p-4 border rounded-lg bg-muted/30">
            <p className="text-sm text-muted-foreground">
                Quick Add Form - Coming in next step
            </p>
        </div>
    );
}
