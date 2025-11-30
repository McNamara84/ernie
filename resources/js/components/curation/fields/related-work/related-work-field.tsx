import { FileUp } from 'lucide-react';
import { useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { IdentifierType, RelatedIdentifier, RelatedIdentifierFormData, RelationType } from '@/types';

import RelatedWorkAdvancedAdd from './related-work-advanced-add';
import RelatedWorkCsvImport from './related-work-csv-import';
import RelatedWorkList from './related-work-list';
import RelatedWorkQuickAdd from './related-work-quick-add';

interface RelatedWorkFieldProps {
    relatedWorks: RelatedIdentifier[];
    onChange: (relatedWorks: RelatedIdentifier[]) => void;
}

/**
 * Auto-detect identifier type from the input value
 * Used to share detection logic across Simple/Advanced modes
 */
function detectIdentifierType(value: string): IdentifierType {
    const trimmed = value.trim();
    
    // DOI with URL prefix
    const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
    if (doiUrlMatch) {
        return 'DOI';
    }
    
    // DOI patterns (without URL prefix)
    if (trimmed.match(/^10\.\d{4,}/)) {
        return 'DOI';
    }
    
    // URL patterns
    if (trimmed.match(/^https?:\/\//i)) {
        return 'URL';
    }
    
    // Handle patterns
    if (trimmed.match(/^\d{5}\//)) {
        return 'Handle';
    }
    
    // Default to DOI if it looks like one
    if (trimmed.includes('/') && !trimmed.includes(' ')) {
        return 'DOI';
    }
    
    return 'URL';
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

/**
 * Normalize identifier to detect duplicates
 * Removes URL prefixes from DOIs to compare bare identifiers
 */
function normalizeIdentifier(identifier: string, identifierType: string): string {
    if (identifierType === 'DOI') {
        // Remove URL prefix from DOI
        const doiUrlMatch = identifier.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
        return doiUrlMatch ? doiUrlMatch[1] : identifier;
    }
    return identifier;
}

/**
 * Check if an identifier already exists (considering normalized form)
 * A duplicate is only when BOTH identifier AND relation type are the same
 * (same identifier with different relation types is valid)
 */
function isDuplicate(
    identifier: string,
    identifierType: string,
    relationType: string,
    existingItems: RelatedIdentifier[]
): boolean {
    const normalized = normalizeIdentifier(identifier, identifierType);
    
    return existingItems.some(item => {
        // Must match both identifier type AND relation type
        if (item.identifier_type !== identifierType || item.relation_type !== relationType) {
            return false;
        }
        const existingNormalized = normalizeIdentifier(item.identifier, item.identifier_type);
        return existingNormalized.toLowerCase() === normalized.toLowerCase();
    });
}

export default function RelatedWorkField({
    relatedWorks,
    onChange,
}: RelatedWorkFieldProps) {
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [showCsvImport, setShowCsvImport] = useState(false);
    const [duplicateError, setDuplicateError] = useState<string | null>(null);
    
    // Shared form state - persisted across mode switches
    const [identifier, setIdentifier] = useState('');
    const [identifierType, setIdentifierType] = useState<IdentifierType>('DOI');
    const [relationType, setRelationType] = useState<RelationType>('Cites');

    // Update identifierType when identifier changes in simple mode (auto-detection)
    const handleIdentifierChange = (value: string, autoDetect: boolean = false) => {
        setIdentifier(value);
        if (autoDetect) {
            setIdentifierType(detectIdentifierType(value));
        }
    };

    const handleAdd = (data: RelatedIdentifierFormData) => {
        // Check for duplicates (same identifier AND same relation type)
        if (isDuplicate(data.identifier, data.identifierType, data.relationType, relatedWorks)) {
            setDuplicateError(`This exact relation already exists in the list (same identifier and relation type). Note: You can add the same identifier with a different relation type.`);
            
            // Clear error after 5 seconds
            setTimeout(() => setDuplicateError(null), 5000);
            return;
        }

        // Clear any previous error
        setDuplicateError(null);

        const newItem: RelatedIdentifier = {
            identifier: data.identifier,
            identifier_type: data.identifierType,
            relation_type: data.relationType,
            position: relatedWorks.length,
        };

        onChange([...relatedWorks, newItem]);
        
        // Reset form after successful add
        setIdentifier('');
        setIdentifierType('DOI');
        setRelationType('Cites');
    };

    const handleBulkImport = (data: RelatedIdentifierFormData[]) => {
        // Filter out duplicates from CSV import
        const combinedList = [...relatedWorks];
        const skippedDuplicates: string[] = [];

        data.forEach((item) => {
            if (isDuplicate(item.identifier, item.identifierType, item.relationType, combinedList)) {
                skippedDuplicates.push(`${item.identifier} (${item.relationType})`);
            } else {
                combinedList.push({
                    identifier: item.identifier,
                    identifier_type: item.identifierType,
                    relation_type: item.relationType,
                    position: combinedList.length,
                });
            }
        });

        onChange(combinedList);
        setShowCsvImport(false);

        // Show warning if duplicates were skipped
        if (skippedDuplicates.length > 0) {
            setDuplicateError(
                `Skipped ${skippedDuplicates.length} duplicate(s) from CSV import: ${skippedDuplicates.slice(0, 3).join(', ')}${skippedDuplicates.length > 3 ? '...' : ''}`
            );
            setTimeout(() => setDuplicateError(null), 8000);
        }
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
            {/* Duplicate Error Alert */}
            {duplicateError && (
                <Alert variant="destructive">
                    <AlertDescription>{duplicateError}</AlertDescription>
                </Alert>
            )}

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
                            identifier={identifier}
                            onIdentifierChange={(value) => handleIdentifierChange(value, true)}
                            relationType={relationType}
                            onRelationTypeChange={setRelationType}
                        />
                    ) : (
                        <>
                            <RelatedWorkAdvancedAdd
                                onAdd={handleAdd}
                                identifier={identifier}
                                onIdentifierChange={(value) => handleIdentifierChange(value, false)}
                                identifierType={identifierType}
                                onIdentifierTypeChange={setIdentifierType}
                                relationType={relationType}
                                onRelationTypeChange={setRelationType}
                            />
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
