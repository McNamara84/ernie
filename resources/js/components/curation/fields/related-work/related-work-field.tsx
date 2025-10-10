import { FileUp } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { RelatedIdentifier, RelatedIdentifierFormData } from '@/types';

import RelatedWorkAdvancedAdd from './related-work-advanced-add';
import RelatedWorkCsvImport from './related-work-csv-import';
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
 * - CSV Bulk Import
 * - List of added items
 */
export default function RelatedWorkField({
    relatedWorks,
    onChange,
}: RelatedWorkFieldProps) {
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [showCsvImport, setShowCsvImport] = useState(false);

    const handleAdd = (data: RelatedIdentifierFormData) => {
        const newItem: RelatedIdentifier = {
            identifier: data.identifier,
            identifier_type: data.identifierType,
            relation_type: data.relationType,
            position: relatedWorks.length,
        };

        onChange([...relatedWorks, newItem]);
    };

    const handleBulkImport = (data: RelatedIdentifierFormData[]) => {
        const newItems: RelatedIdentifier[] = data.map((item, index) => ({
            identifier: item.identifier,
            identifier_type: item.identifierType,
            relation_type: item.relationType,
            position: relatedWorks.length + index,
        }));

        onChange([...relatedWorks, ...newItems]);
        setShowCsvImport(false);
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
            {/* CSV Import Modal */}
            {showCsvImport ? (
                <div className="rounded-lg border bg-card p-6">
                    <RelatedWorkCsvImport
                        onImport={handleBulkImport}
                        onClose={() => setShowCsvImport(false)}
                    />
                </div>
            ) : (
                <>
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

                    {/* CSV Import Button */}
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setShowCsvImport(true)}
                        >
                            <FileUp className="mr-2 h-4 w-4" />
                            Import from CSV
                        </Button>
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
