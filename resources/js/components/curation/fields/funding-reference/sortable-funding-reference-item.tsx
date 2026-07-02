import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';

import { Button } from '@/components/ui/button';

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
        <div ref={setNodeRef} style={style} className="relative flex items-start gap-3">
            {/* Drag Handle */}
            <div className="flex-shrink-0 pt-5">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 cursor-grab touch-none text-muted-foreground hover:text-foreground active:cursor-grabbing"
                    aria-label={`Drag to reorder funding ${props.index + 1}`}
                    {...attributes}
                    {...listeners}
                >
                    <GripVertical className="h-5 w-5" />
                </Button>
            </div>

            {/* The actual FundingReferenceItem */}
            <div className="flex-1">
                <FundingReferenceItem {...props} />
            </div>
        </div>
    );
}
