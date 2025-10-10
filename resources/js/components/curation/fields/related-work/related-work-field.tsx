import { useState } from 'react';

import type { RelatedIdentifier, RelatedIdentifierFormData } from '@/types';

import RelatedWorkAdvancedAdd from './related-work-advanced-add';
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
 * - Quick Add form (Top 5 most used)
 * - Advanced Add form (All 33 relation types)
 * - List of added items
 * - Validation (future)
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
            {/* Quick or Advanced Mode */}
            {!showAdvanced ? (
                <RelatedWorkQuickAdd
                    onAdd={handleAdd}
                    showAdvancedMode={showAdvanced}
                    onToggleAdvanced={handleToggleAdvanced}
                />
            ) : (
                <>
                    <RelatedWorkAdvancedAdd onAdd={handleAdd} />
                    <div className="pt-2">
                        <button
                            type="button"
                            onClick={handleToggleAdvanced}
                            className="text-xs text-muted-foreground hover:text-foreground underline"
                        >
                            ← Switch to simple mode
                        </button>
                    </div>
                </>
            )}

            {/* List of Added Items */}
            {relatedWorks.length > 0 && (
                <RelatedWorkList
                    items={relatedWorks}
                    onRemove={handleRemove}
                />
            )}
        </div>
    );
}
