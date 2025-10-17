/**
 * AuthorCsvImport Component
 * 
 * CSV bulk import for authors.
 * Similar to related-work CSV import functionality.
 */

import React from 'react';

/**
 * AuthorCsvImport - CSV bulk import
 * TODO: Implement full CSV import functionality with proper props:
 * - onImport: (authors: AuthorEntry[]) => void
 * - onClose: () => void
 */
export default function AuthorCsvImport() {
    return (
        <div className="p-6">
            <h3 className="text-lg font-semibold mb-4">Import Authors from CSV</h3>
            <p className="text-sm text-muted-foreground">
                CSV Import functionality - Coming in Phase 5
            </p>
        </div>
    );
}
