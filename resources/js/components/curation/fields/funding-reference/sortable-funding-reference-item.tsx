import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';

import { FundingReferenceItem } from './funding-reference-item';
import type { FundingReferenceEntry, RorFunder } from './types';

interface SortableFundingReferenceItemProps {
    funding: FundingReferenceEntry;
    index: number;
    onFunderNameChange: (value: string) => void;
    onFieldsChange: (fields: Partial<FundingReferenceEntry>) => void;
    onAwardNumberChange: (value: string) => void;
    onAwardUriChange: (value: string) => void;
    onAwardTitleChange: (value: string) => void;
    onToggleExpanded: () => void;
    onRemove: () => void;
    canRemove: boolean;
    rorFunders: RorFunder[];
}

export function SortableFundingReferenceItem(props: SortableFundingReferenceItemProps) {
    const { funding } = props;

    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: funding.id,
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    return (
        <div ref={setNodeRef} style={style} className="relative flex items-start gap-2">
            {/* Drag Handle */}
            <div
                {...attributes}
                {...listeners}
                className="flex-shrink-0 cursor-grab touch-none pt-6 active:cursor-grabbing"
                aria-label="Drag to reorder"
            >
                <div className="rounded-md bg-muted p-1 transition-colors hover:bg-muted/80">
                    <GripVertical className="h-5 w-5 text-muted-foreground" />
                </div>
            </div>

            {/* The actual FundingReferenceItem */}
            <div className="flex-1">
                <FundingReferenceItem {...props} />
            </div>
        </div>
    );
}
