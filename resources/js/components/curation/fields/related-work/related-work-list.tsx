import { closestCenter, DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';

import type { RelatedIdentifier } from '@/types';

import RelatedWorkItem from './related-work-item';

export interface CitationResolutionState {
    status: 'resolving' | 'resolved' | 'unavailable';
    message?: string;
}

interface RelatedWorkListProps {
    items: RelatedIdentifier[];
    completedItemCount?: number;
    onItemChange: (index: number, item: RelatedIdentifier) => void;
    onIdentifierBlur?: (index: number) => void;
    onRemove: (index: number) => void;
    onReorder: (items: RelatedIdentifier[]) => void;
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
    validationStatuses?: Map<
        number,
        {
            status: 'validating' | 'valid' | 'invalid' | 'warning';
            message?: string;
        }
    >;
    citationResolutionStates?: Map<number, CitationResolutionState>;
}

/**
 * RelatedWorkList Component
 *
 * Displays editable related work cards with drag-and-drop ordering.
 */
export default function RelatedWorkList({
    items,
    completedItemCount = items.filter((item) => item.identifier.trim() !== '').length,
    onItemChange,
    onIdentifierBlur,
    onRemove,
    onReorder,
    activeRelationTypes,
    activeIdentifierTypes,
    validationStatuses,
    citationResolutionStates,
}: RelatedWorkListProps) {
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    if (items.length === 0) {
        return null;
    }

    const getSortableId = (index: number) => `related-work-${index}`;

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (!over || active.id === over.id) {
            return;
        }

        const oldIndex = items.findIndex((_, index) => getSortableId(index) === active.id);
        const newIndex = items.findIndex((_, index) => getSortableId(index) === over.id);

        if (oldIndex === -1 || newIndex === -1) {
            return;
        }

        onReorder(arrayMove(items, oldIndex, newIndex));
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h4 className="text-sm font-medium text-foreground">Added Relations ({completedItemCount})</h4>
                {items.length > 1 && <span className="text-xs text-muted-foreground">Drag cards to reorder them</span>}
            </div>

            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={items.map((_, index) => getSortableId(index))} strategy={verticalListSortingStrategy}>
                    <div className="space-y-3" role="list" aria-label="Related works">
                        {items.map((item, index) => {
                            const validation = validationStatuses?.get(index);
                            const citationResolution = citationResolutionStates?.get(index);

                            return (
                                <RelatedWorkItem
                                    key={getSortableId(index)}
                                    sortableId={getSortableId(index)}
                                    item={item}
                                    index={index}
                                    onChange={(updatedItem) => onItemChange(index, updatedItem)}
                                    onIdentifierBlur={() => onIdentifierBlur?.(index)}
                                    onRemove={onRemove}
                                    activeRelationTypes={activeRelationTypes}
                                    activeIdentifierTypes={activeIdentifierTypes}
                                    validationStatus={validation?.status}
                                    validationMessage={validation?.message}
                                    citationResolutionStatus={citationResolution?.status}
                                    citationResolutionMessage={citationResolution?.message}
                                />
                            );
                        })}
                    </div>
                </SortableContext>
            </DndContext>
        </div>
    );
}
