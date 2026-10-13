import { closestCenter, DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ArrowDown, ArrowUp, GripVertical, Plus, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { LandingPageDownloadUrlSuggestionItem } from '@/types/landing-page';

interface Entry {
    id: string;
    value: string;
}

function SuggestionRow({
    entry,
    index,
    count,
    onEdit,
    onMove,
    onRemove,
    error,
}: {
    entry: Entry;
    index: number;
    count: number;
    onEdit: (value: string) => void;
    onMove: (index: number) => void;
    onRemove: () => void;
    error?: string;
}) {
    const { attributes, listeners, setNodeRef, transform, transition } = useSortable({ id: entry.id });

    return (
        <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }} className="space-y-1 rounded-md border p-2">
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="ghost" size="icon" aria-label={`Reorder suggestion ${index + 1}`} {...attributes} {...listeners}>
                    <GripVertical className="size-4" />
                </Button>
                <Input
                    type="url"
                    aria-label={`Download URL suggestion ${index + 1}`}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${entry.id}-error` : undefined}
                    className="min-w-40 flex-1"
                    value={entry.value}
                    maxLength={2048}
                    onChange={(event) => onEdit(event.target.value)}
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    disabled={index === 0}
                    aria-label={`Move suggestion ${index + 1} up`}
                    onClick={() => onMove(index - 1)}
                >
                    <ArrowUp className="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    disabled={index === count - 1}
                    aria-label={`Move suggestion ${index + 1} down`}
                    onClick={() => onMove(index + 1)}
                >
                    <ArrowDown className="size-4" />
                </Button>
                <Button type="button" variant="ghost" size="icon" aria-label={`Remove suggestion ${index + 1}`} onClick={onRemove}>
                    <X className="size-4" />
                </Button>
            </div>
            {error && (
                <p id={`${entry.id}-error`} role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </li>
    );
}

export function DownloadUrlSuggestions({
    order,
    suggestions,
    onChange,
    errors = {},
}: {
    order: string[];
    suggestions: LandingPageDownloadUrlSuggestionItem[];
    onChange: (order: string[]) => void;
    errors?: Record<string, string>;
}) {
    const [entries, setEntries] = useState<Entry[]>(() =>
        [...new Set([...order, ...suggestions.map((item) => item.value)])].map((value) => ({ id: crypto.randomUUID(), value })),
    );
    const [newPrefix, setNewPrefix] = useState('');
    const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
    const update = (next: Entry[]) => {
        setEntries(next);
        onChange(next.map((entry) => entry.value));
    };
    const move = (from: number, to: number) => update(arrayMove(entries, from, to));
    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (over && active.id !== over.id) {
            move(
                entries.findIndex((entry) => entry.id === active.id),
                entries.findIndex((entry) => entry.id === over.id),
            );
        }
    };

    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                Arrange domains and URL prefixes in the order offered in landing page setup. New domains follow this list automatically. Removing an
                entry removes its priority; domains still in use can appear again. Changes take effect after Save Changes.
            </p>
            {errors.downloadUrlSuggestionOrder && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.downloadUrlSuggestionOrder}
                </p>
            )}
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={entries.map((entry) => entry.id)} strategy={verticalListSortingStrategy}>
                    <ol aria-label="Download URL suggestions" className="space-y-2">
                        {entries.map((entry, index) => (
                            <SuggestionRow
                                key={entry.id}
                                entry={entry}
                                index={index}
                                count={entries.length}
                                onEdit={(value) => update(entries.map((item) => (item.id === entry.id ? { ...item, value } : item)))}
                                onMove={(to) => move(index, to)}
                                onRemove={() => update(entries.filter((item) => item.id !== entry.id))}
                                error={errors[`downloadUrlSuggestionOrder.${index}`]}
                            />
                        ))}
                    </ol>
                </SortableContext>
            </DndContext>
            <div className="flex flex-wrap gap-2">
                <Input
                    aria-label="New download URL prefix"
                    type="url"
                    placeholder="https://example.org/download"
                    maxLength={2048}
                    value={newPrefix}
                    onChange={(event) => setNewPrefix(event.target.value)}
                    className="min-w-40 flex-1"
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={newPrefix.trim() === ''}
                    onClick={() => {
                        update([...entries, { id: crypto.randomUUID(), value: newPrefix.trim() }]);
                        setNewPrefix('');
                    }}
                >
                    <Plus className="mr-2 size-4" />
                    Add prefix
                </Button>
            </div>
        </div>
    );
}
