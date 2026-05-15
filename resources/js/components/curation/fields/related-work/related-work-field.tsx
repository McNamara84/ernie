import { FileUp } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { getCitation } from '@/actions/App/Http/Controllers/Api/DataCiteController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { normalizeDOI } from '@/lib/doi-validation';
import { detectIdentifierType } from '@/lib/identifier-type-detection';
import type { IdentifierType, RelatedIdentifier, RelatedIdentifierFormData, RelationType } from '@/types';

import RelatedWorkCsvImport from './related-work-csv-import';
import RelatedWorkList from './related-work-list';
import RelatedWorkQuickAdd from './related-work-quick-add';

interface RelatedWorkFieldProps {
    relatedWorks: RelatedIdentifier[];
    onChange: (relatedWorks: RelatedIdentifier[]) => void;
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
}

const BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY = 3;

/**
 * RelatedWorkField Component
 *
 * Main container for the Related Work functionality.
 * Manages state and coordinates between:
 * - Quick Add form (Top 5 most used)
 * - Advanced Add form (All DataCite relation types)
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
function isDuplicate(identifier: string, identifierType: string, relationType: string, existingItems: RelatedIdentifier[]): boolean {
    const normalized = normalizeIdentifier(identifier, identifierType);

    return existingItems.some((item) => {
        // Must match both identifier type AND relation type
        if (item.identifier_type !== identifierType || item.relation_type !== relationType) {
            return false;
        }
        const existingNormalized = normalizeIdentifier(item.identifier, item.identifier_type);
        return existingNormalized.toLowerCase() === normalized.toLowerCase();
    });
}

export default function RelatedWorkField({ relatedWorks, onChange, activeRelationTypes, activeIdentifierTypes }: RelatedWorkFieldProps) {
    const [showCsvImport, setShowCsvImport] = useState(false);
    const [duplicateError, setDuplicateError] = useState<string | null>(null);
    const relatedWorksRef = useRef(relatedWorks);

    // Shared form state - persisted across mode switches
    const [identifier, setIdentifier] = useState('');
    const [identifierType, setIdentifierType] = useState<IdentifierType>('DOI');
    const [identifierTypeWasManuallySelected, setIdentifierTypeWasManuallySelected] = useState(false);
    const [relationType, setRelationType] = useState<RelationType>('Cites');

    useEffect(() => {
        relatedWorksRef.current = relatedWorks;
    }, [relatedWorks]);

    const assignPositions = (items: RelatedIdentifier[]) =>
        items.map((item, index) => ({
            ...item,
            position: index,
        }));

    const hydrateCitationLabel = async (identifier: string, itemIdentifierType: string, itemRelationType: string) => {
        if (itemIdentifierType !== 'DOI') {
            return;
        }

        try {
            const response = await fetch(
                getCitation.url({
                    query: {
                        doi: normalizeDOI(identifier),
                    },
                }),
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                return;
            }

            const payload = (await response.json()) as { citation?: unknown };
            const citationLabel = typeof payload.citation === 'string' ? payload.citation.trim() : '';

            if (!citationLabel) {
                return;
            }

            let didUpdate = false;

            const updated = relatedWorksRef.current.map((item) => {
                if (
                    item.identifier === identifier
                    && item.identifier_type === itemIdentifierType
                    && item.relation_type === itemRelationType
                    && !item.citation_label?.trim()
                ) {
                    didUpdate = true;

                    return {
                        ...item,
                        citation_label: citationLabel,
                    };
                }

                return item;
            });

            if (!didUpdate) {
                return;
            }

            relatedWorksRef.current = updated;
            onChange(updated);
        } catch {
            // Saving will backfill citation labels server-side when client-side lookup fails.
        }
    };

    const hydrateCitationLabelsForImportedItems = async (items: RelatedIdentifier[]) => {
        const itemsNeedingHydration = items.filter((item) => item.identifier_type === 'DOI' && !item.citation_label?.trim());

        for (let index = 0; index < itemsNeedingHydration.length; index += BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY) {
            const chunk = itemsNeedingHydration.slice(index, index + BULK_IMPORT_CITATION_HYDRATION_CONCURRENCY);

            await Promise.all(chunk.map((item) => hydrateCitationLabel(item.identifier, item.identifier_type, item.relation_type)));
        }
    };

    // Update identifierType when identifier changes in simple mode (auto-detection)
    const handleIdentifierChange = (value: string, autoDetect: boolean = false) => {
        setIdentifier(value);

        if (value.trim() === '') {
            setIdentifierTypeWasManuallySelected(false);
        }

        if (autoDetect && !identifierTypeWasManuallySelected) {
            setIdentifierType(detectIdentifierType(value));
        }
    };

    const handleIdentifierTypeChange = (value: IdentifierType) => {
        setIdentifierType(value);
        setIdentifierTypeWasManuallySelected(true);
    };

    const handleAdd = (data: RelatedIdentifierFormData) => {
        // Check for duplicates (same identifier AND same relation type)
        if (isDuplicate(data.identifier, data.identifierType, data.relationType, relatedWorks)) {
            setDuplicateError(
                `This exact relation already exists in the list (same identifier and relation type). Note: You can add the same identifier with a different relation type.`,
            );

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
            ...(data.relationTypeInformation ? { relation_type_information: data.relationTypeInformation } : {}),
            ...(data.citationLabel ? { citation_label: data.citationLabel } : {}),
            position: relatedWorks.length,
        };

        const updated = [...relatedWorks, newItem];
        relatedWorksRef.current = updated;
        onChange(updated);

        if (!newItem.citation_label?.trim()) {
            void hydrateCitationLabel(newItem.identifier, newItem.identifier_type, newItem.relation_type);
        }

        // Reset form after successful add
        setIdentifier('');
        setIdentifierType('DOI');
        setIdentifierTypeWasManuallySelected(false);
        setRelationType('Cites');
    };

    const handleBulkImport = (data: RelatedIdentifierFormData[]) => {
        // Filter out duplicates from CSV import
        const combinedList = [...relatedWorks];
        const importedItems: RelatedIdentifier[] = [];
        const skippedDuplicates: string[] = [];

        data.forEach((item) => {
            if (isDuplicate(item.identifier, item.identifierType, item.relationType, combinedList)) {
                skippedDuplicates.push(`${item.identifier} (${item.relationType})`);
            } else {
                const importedItem: RelatedIdentifier = {
                    identifier: item.identifier,
                    identifier_type: item.identifierType,
                    relation_type: item.relationType,
                    ...(item.relationTypeInformation ? { relation_type_information: item.relationTypeInformation } : {}),
                    ...(item.citationLabel ? { citation_label: item.citationLabel } : {}),
                    position: combinedList.length,
                };

                combinedList.push(importedItem);
                importedItems.push(importedItem);
            }
        });

        relatedWorksRef.current = combinedList;
        onChange(combinedList);
        setShowCsvImport(false);

        void hydrateCitationLabelsForImportedItems(importedItems);

        // Show warning if duplicates were skipped
        if (skippedDuplicates.length > 0) {
            setDuplicateError(
                `Skipped ${skippedDuplicates.length} duplicate(s) from CSV import: ${skippedDuplicates.slice(0, 3).join(', ')}${skippedDuplicates.length > 3 ? '...' : ''}`,
            );
            setTimeout(() => setDuplicateError(null), 8000);
        }
    };

    const handleRemove = (index: number) => {
        const reindexed = assignPositions(relatedWorks.filter((_, i) => i !== index));

        relatedWorksRef.current = reindexed;
        onChange(reindexed);
    };

    const handleItemChange = (index: number, updatedItem: RelatedIdentifier) => {
        const previousItem = relatedWorks[index];
        const identifierChanged =
            previousItem.identifier !== updatedItem.identifier || previousItem.identifier_type !== updatedItem.identifier_type;

        const updated = relatedWorks.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            return {
                ...updatedItem,
                citation_label: identifierChanged ? null : updatedItem.citation_label ?? null,
                related_title: identifierChanged ? null : updatedItem.related_title ?? null,
                related_metadata: identifierChanged ? null : updatedItem.related_metadata ?? null,
                position: itemIndex,
            };
        });

        relatedWorksRef.current = updated;
        onChange(updated);
    };

    const handleReorder = (items: RelatedIdentifier[]) => {
        const reindexed = assignPositions(items);

        relatedWorksRef.current = reindexed;
        onChange(reindexed);
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
                        activeRelationTypes={activeRelationTypes}
                        activeIdentifierTypes={activeIdentifierTypes}
                    />
                </div>
            ) : (
                <>
                    <RelatedWorkQuickAdd
                        onAdd={handleAdd}
                        identifier={identifier}
                        onIdentifierChange={(value) => handleIdentifierChange(value, true)}
                        identifierType={identifierType}
                        onIdentifierTypeChange={handleIdentifierTypeChange}
                        relationType={relationType}
                        onRelationTypeChange={setRelationType}
                        activeRelationTypes={activeRelationTypes}
                        activeIdentifierTypes={activeIdentifierTypes}
                    />

                    {/* CSV Import Button */}
                    <div className="flex justify-end">
                        <Button type="button" variant="outline" size="sm" onClick={() => setShowCsvImport(true)}>
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
                    onItemChange={handleItemChange}
                    onRemove={handleRemove}
                    onReorder={handleReorder}
                    activeRelationTypes={activeRelationTypes}
                    activeIdentifierTypes={activeIdentifierTypes}
                />
            )}
        </div>
    );
}
