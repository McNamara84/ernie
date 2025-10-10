import { useState } from 'react';

import type { RelatedIdentifier, RelatedIdentifierFormData } from '@/types';

import RelatedWorkList from './related-work-list';
import RelatedWorkQuickAdd from './related-work-quick-add';

interface RelatedWorkFieldProps {
    relatedWorks: RelatedIdentifier[];
    onChange: (relatedWorks: RelatedIdentifier[]) => void;
}

/**
 * RelatedWorkField Component
 * 
 * Main container for the Related Work functionality.
 * Manages state and coordinates between:
 * - Quick Add form
 * - List of added items
 * - Validation (future)
 * - Advanced mode (future)
 */
export default function RelatedWorkField({
    relatedWorks,
    onChange,
}: RelatedWorkFieldProps) {
    const [showAdvanced, setShowAdvanced] = useState(false);

    const handleAdd = (data: RelatedIdentifierFormData) => {
        const newItem: RelatedIdentifier = {
            identifier: data.identifier,
            identifier_type: data.identifierType,
            relation_type: data.relationType,
            position: relatedWorks.length,
        };

        onChange([...relatedWorks, newItem]);
    };

    const handleRemove = (index: number) => {
        const updated = relatedWorks.filter((_, i) => i !== index);
        
        // Re-assign positions after removal
        const reindexed = updated.map((item, i) => ({
            ...item,
            position: i,
        }));

        onChange(reindexed);
    };

    const handleToggleAdvanced = () => {
        setShowAdvanced(!showAdvanced);
    };

    return (
        <div className="space-y-6">
            {/* Quick Add Component */}
            <RelatedWorkQuickAdd
                onAdd={handleAdd}
                showAdvancedMode={showAdvanced}
                onToggleAdvanced={handleToggleAdvanced}
            />

            {/* List of Added Items */}
            {relatedWorks.length > 0 && (
                <RelatedWorkList
                    items={relatedWorks}
                    onRemove={handleRemove}
                />
            )}

            {/* Placeholder for Advanced Mode */}
            {showAdvanced && (
                <div className="rounded-lg border border-dashed border-muted-foreground/25 p-8 text-center">
                    <p className="text-sm text-muted-foreground">
                        Advanced mode (all 33 relation types) - Coming soon
                    </p>
                </div>
            )}
        </div>
    );
}
