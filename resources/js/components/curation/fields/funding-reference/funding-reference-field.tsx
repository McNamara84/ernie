import type { DragEndEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

import { getFunderByRorId, loadRorFunders } from './ror-search';
import { SortableFundingReferenceItem } from './sortable-funding-reference-item';
import type { FundingReferenceEntry, RorFunder } from './types';
import { MAX_FUNDING_REFERENCES } from './types';

interface FundingReferenceFieldProps {
    value: FundingReferenceEntry[];
    onChange: (fundings: FundingReferenceEntry[]) => void;
}

export function FundingReferenceField({
    value = [],
    onChange,
}: FundingReferenceFieldProps) {
    const [rorFunders, setRorFunders] = useState<RorFunder[]>([]);
    const [isLoadingRor, setIsLoadingRor] = useState(true);

    // Sensors for drag and drop
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    // Load ROR data on mount
    useEffect(() => {
        const loadData = async () => {
            try {
                const funders = await loadRorFunders();
                setRorFunders(funders);
            } catch (error) {
                console.error('Failed to load ROR funders:', error);
            } finally {
                setIsLoadingRor(false);
            }
        };
        loadData();
    }, []);

    // Auto-fill funder names from ROR IDs when ROR data is loaded
    useEffect(() => {
        if (!isLoadingRor && rorFunders.length > 0) {
            const updated = value.map((funding) => {
                // If funder name is empty but ROR ID exists, fill it from ROR data
                if (!funding.funderName && funding.funderIdentifier && funding.funderIdentifierType === 'ROR') {
                    const rorFunder = getFunderByRorId(rorFunders, funding.funderIdentifier);
                    if (rorFunder) {
                        return {
                            ...funding,
                            funderName: rorFunder.prefLabel,
                        };
                    }
                }
                return funding;
            });

            // Only update if something changed
            if (JSON.stringify(updated) !== JSON.stringify(value)) {
                onChange(updated);
            }
        }
    }, [isLoadingRor, rorFunders, value, onChange]);

    const handleAdd = () => {
        if (value.length >= MAX_FUNDING_REFERENCES) return;

        const newFunding: FundingReferenceEntry = {
            id: `funding-${Date.now()}`,
            funderName: '',
            funderIdentifier: '',
            funderIdentifierType: null,
            awardNumber: '',
            awardUri: '',
            awardTitle: '',
            isExpanded: false,
        };

        onChange([...value, newFunding]);
    };

    const handleRemove = (index: number) => {
        const updated = value.filter((_, i) => i !== index);
        onChange(updated);
    };

    const handleFieldChange = (
        index: number,
        field: keyof FundingReferenceEntry,
        fieldValue: string | boolean
    ) => {
        const updated = value.map((funding, i) =>
            i === index ? { ...funding, [field]: fieldValue } : funding
        );
        onChange(updated);
    };

    const handleFieldsChange = (index: number, fields: Partial<FundingReferenceEntry>) => {
        const updated = value.map((funding, i) =>
            i === index ? { ...funding, ...fields } : funding
        );
        onChange(updated);
    };

    const handleToggleExpanded = (index: number) => {
        handleFieldChange(index, 'isExpanded', !value[index].isExpanded);
    };

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (over && active.id !== over.id) {
            const oldIndex = value.findIndex((item) => item.id === active.id);
            const newIndex = value.findIndex((item) => item.id === over.id);

            const reordered = arrayMove(value, oldIndex, newIndex);
            // Update position property to reflect new order
            const withUpdatedPositions = reordered.map((item, idx) => ({
                ...item,
                position: idx,
            }));
            onChange(withUpdatedPositions);
        }
    };

    const canAdd = value.length < MAX_FUNDING_REFERENCES;
    const canRemove = value.length > 0;

    return (
        <div className="space-y-6">
            {/* Info Header */}
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    {value.length} / {MAX_FUNDING_REFERENCES} funding reference
                    {value.length !== 1 ? 's' : ''}
                </p>
                {isLoadingRor && (
                    <p className="text-xs text-muted-foreground">
                        Loading ROR data...
                    </p>
                )}
            </div>

            {/* List of Funding References */}
            {value.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border bg-muted/30 p-12 text-center">
                    <p className="text-sm text-muted-foreground">
                        No funding references added yet.
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Click "Add Funding Reference" to get started.
                    </p>
                </div>
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext
                        items={value.map((f) => f.id)}
                        strategy={verticalListSortingStrategy}
                    >
                        <div className="space-y-4">
                            {value.map((funding, index) => (
                                <SortableFundingReferenceItem
                                    key={funding.id}
                                    funding={funding}
                                    index={index}
                                    onFunderNameChange={(val: string) =>
                                        handleFieldChange(index, 'funderName', val)
                                    }
                                    onFunderIdentifierChange={(val: string) =>
                                        handleFieldChange(index, 'funderIdentifier', val)
                                    }
                                    onFieldsChange={(fields: Partial<FundingReferenceEntry>) =>
                                        handleFieldsChange(index, fields)
                                    }
                                    onAwardNumberChange={(val: string) =>
                                        handleFieldChange(index, 'awardNumber', val)
                                    }
                                    onAwardUriChange={(val: string) =>
                                        handleFieldChange(index, 'awardUri', val)
                                    }
                                    onAwardTitleChange={(val: string) =>
                                        handleFieldChange(index, 'awardTitle', val)
                                    }
                                    onToggleExpanded={() => handleToggleExpanded(index)}
                                    onRemove={() => handleRemove(index)}
                                    canRemove={canRemove}
                                    rorFunders={rorFunders}
                                />
                            ))}
                        </div>
                    </SortableContext>
                </DndContext>
            )}

            {/* Add Button */}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAdd}
                disabled={!canAdd}
                className="w-full"
            >
                <Plus className="mr-2 h-4 w-4" />
                Add Funding Reference
                {!canAdd && ' (Maximum reached)'}
            </Button>
        </div>
    );
}
