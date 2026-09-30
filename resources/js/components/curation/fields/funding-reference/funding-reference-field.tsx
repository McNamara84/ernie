import type { DragEndEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Banknote, Plus } from 'lucide-react';
import { useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { indexRorSuggestions } from '@/lib/ror-input';

import { RorCatalogContext } from '../ror-input-feedback';
import { getFunderByRorId, loadRorFunders } from './ror-search';
import { SortableFundingReferenceItem } from './sortable-funding-reference-item';
import type { FundingReferenceEntry, RorFunder } from './types';

interface FundingReferenceFieldProps {
    value: FundingReferenceEntry[];
    onChange: (fundings: FundingReferenceEntry[]) => void;
}

export function FundingReferenceField({ value = [], onChange }: FundingReferenceFieldProps) {
    const catalog = useContext(RorCatalogContext);
    const [localFunders, setRorFunders] = useState<RorFunder[]>([]);
    const [localLoading, setIsLoadingRor] = useState(true);
    const [fallbackAttempt, setFallbackAttempt] = useState(0);
    const retryFallback = useCallback(() => setFallbackAttempt((attempt) => attempt + 1), []);
    const hasSharedCatalog = !!catalog && !catalog.isLoading && !catalog.error && catalog.index.size > 0;
    const shouldLoadFallback = !catalog?.isLoading && !hasSharedCatalog;
    const localCatalog = useMemo(() => {
        const suggestions = localFunders.map((item) => ({ value: item.prefLabel, rorId: item.rorId, searchTerms: item.otherLabel }));
        return { suggestions, index: indexRorSuggestions(suggestions), isLoading: localLoading, error: null, retry: retryFallback };
    }, [localFunders, localLoading, retryFallback]);
    const effectiveCatalog = catalog && (catalog.isLoading || hasSharedCatalog) ? catalog : localCatalog;
    const rorFunders = useMemo(
        () => effectiveCatalog.suggestions.map((item) => ({ prefLabel: item.value, rorId: item.rorId ?? '', otherLabel: item.searchTerms })),
        [effectiveCatalog.suggestions],
    );
    const isLoadingRor = effectiveCatalog.isLoading;

    // Sensors for drag and drop
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    // Wait for the shared request, then fall back if it has no usable catalog.
    useEffect(() => {
        if (!shouldLoadFallback) return;
        let active = true;
        setIsLoadingRor(true);
        setRorFunders([]);
        const loadData = async () => {
            try {
                const funders = await loadRorFunders();
                if (active) setRorFunders(funders);
            } catch (error) {
                console.error('Failed to load ROR funders:', error);
            } finally {
                if (active) setIsLoadingRor(false);
            }
        };
        void loadData();
        return () => {
            active = false;
        };
    }, [shouldLoadFallback, fallbackAttempt]);

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
        const newFunding: FundingReferenceEntry = {
            id: `funding-${crypto.randomUUID()}`,
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

    const handleFieldChange = (index: number, field: keyof FundingReferenceEntry, fieldValue: string | boolean) => {
        const updated = value.map((funding, i) => (i === index ? { ...funding, [field]: fieldValue } : funding));
        onChange(updated);
    };

    const handleFieldsChange = (index: number, fields: Partial<FundingReferenceEntry>) => {
        const updated = value.map((funding, i) => (i === index ? { ...funding, ...fields } : funding));
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

    const canRemove = value.length > 0;

    const fundingFields = (
        <div className="space-y-6">
            {isLoadingRor && <p className="text-right text-xs text-muted-foreground">Loading ROR data...</p>}

            {/* List of Funding References */}
            {value.length === 0 ? (
                <EmptyState
                    icon={<Banknote className="h-8 w-8" />}
                    title="No funding references added"
                    description="Add information about grants, awards, and funders that supported this research."
                    action={{
                        label: 'Add Funding Reference',
                        onClick: handleAdd,
                    }}
                    data-testid="funding-empty-state"
                />
            ) : (
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                    <SortableContext items={value.map((f) => f.id)} strategy={verticalListSortingStrategy}>
                        <div className="space-y-4">
                            {value.map((funding, index) => (
                                <SortableFundingReferenceItem
                                    key={funding.id}
                                    funding={funding}
                                    index={index}
                                    onFunderNameChange={(val: string) => handleFieldChange(index, 'funderName', val)}
                                    onFieldsChange={(fields: Partial<FundingReferenceEntry>) => handleFieldsChange(index, fields)}
                                    onAwardNumberChange={(val: string) => handleFieldChange(index, 'awardNumber', val)}
                                    onAwardUriChange={(val: string) => handleFieldChange(index, 'awardUri', val)}
                                    onAwardTitleChange={(val: string) => handleFieldChange(index, 'awardTitle', val)}
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
            {value.length > 0 && (
                <Button type="button" variant="outline" size="sm" onClick={handleAdd} className="w-full">
                    <Plus className="mr-2 h-4 w-4" />
                    Add Funding Reference
                </Button>
            )}
        </div>
    );

    // Funding items need the same effective catalog for name search and exact-ID confirmation.
    return <RorCatalogContext.Provider value={effectiveCatalog}>{fundingFields}</RorCatalogContext.Provider>;
}
