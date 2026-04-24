import { useState } from 'react';
import { toast } from 'sonner';

import { CitationCard } from '@/components/citations/CitationCard';
import {
    RelatedItemForm,
    type RelatedItemFormOption,
    type RelationTypeOption,
} from '@/components/citations/RelatedItemForm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Spinner } from '@/components/ui/spinner';
import { useRelatedItems } from '@/hooks/use-related-items';
import type { RelatedItem } from '@/types/related-item';

interface CitationManagerModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    resourceId: number;
    resourceTypes: RelatedItemFormOption[];
    relationTypes: RelationTypeOption[];
    contributorTypes: RelatedItemFormOption[];
}

type Mode =
    | { type: 'list' }
    | { type: 'create' }
    | { type: 'edit'; item: RelatedItem };

/**
 * Modal wrapper that manages the list of related items for a resource.
 *
 * Shows a list of {@link CitationCard}s for existing items and a
 * {@link RelatedItemForm} for creating or editing entries.
 */
export function CitationManagerModal({
    open,
    onOpenChange,
    resourceId,
    resourceTypes,
    relationTypes,
    contributorTypes,
}: CitationManagerModalProps) {
    const { items, isLoading, error, create, update, remove } = useRelatedItems(resourceId);
    const [mode, setMode] = useState<Mode>({ type: 'list' });
    const [submitting, setSubmitting] = useState(false);

    const relationLabelOf = (item: RelatedItem): string | undefined =>
        relationTypes.find((r) => r.id === item.relation_type_id)?.label;

    const handleCreate = async (values: Parameters<typeof create>[0]) => {
        setSubmitting(true);
        try {
            await create(values);
            toast.success('Related item created');
            setMode({ type: 'list' });
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Failed to create related item');
        } finally {
            setSubmitting(false);
        }
    };

    const handleUpdate = async (
        id: number,
        values: Parameters<typeof update>[1],
    ) => {
        setSubmitting(true);
        try {
            await update(id, values);
            toast.success('Related item updated');
            setMode({ type: 'list' });
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Failed to update related item');
        } finally {
            setSubmitting(false);
        }
    };

    const handleDelete = async (item: RelatedItem) => {
        if (!item.id) return;
        if (!window.confirm('Delete this related item?')) return;
        try {
            await remove(item.id);
            toast.success('Related item deleted');
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Failed to delete');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Citation Manager</DialogTitle>
                    <DialogDescription>
                        Manage DataCite Related Items for this resource.
                    </DialogDescription>
                </DialogHeader>

                {mode.type === 'list' ? (
                    <div className="space-y-3" data-slot="citation-manager-list">
                        {isLoading ? (
                            <div className="flex items-center justify-center p-8">
                                <Spinner />
                            </div>
                        ) : error ? (
                            <p className="text-sm text-destructive">{error}</p>
                        ) : items.length === 0 ? (
                            <EmptyState
                                title="No related items yet"
                                description="Add citations, references, or supplementary works."
                            />
                        ) : (
                            items.map((item) => (
                                <CitationCard
                                    key={item.id}
                                    item={item}
                                    relationLabel={relationLabelOf(item)}
                                    editable
                                    onEdit={(it) => setMode({ type: 'edit', item: it })}
                                    onDelete={handleDelete}
                                />
                            ))
                        )}

                        <DialogFooter className="gap-2 sm:justify-between">
                            <Button variant="outline" onClick={() => onOpenChange(false)}>
                                Close
                            </Button>
                            <Button onClick={() => setMode({ type: 'create' })}>
                                Add related item
                            </Button>
                        </DialogFooter>
                    </div>
                ) : mode.type === 'create' ? (
                    <div data-slot="citation-manager-create">
                        <RelatedItemForm
                            resourceTypes={resourceTypes}
                            relationTypes={relationTypes}
                            contributorTypes={contributorTypes}
                            submitting={submitting}
                            onCancel={() => setMode({ type: 'list' })}
                            onSubmit={handleCreate}
                        />
                    </div>
                ) : (
                    <div data-slot="citation-manager-edit">
                        <RelatedItemForm
                            initialValue={mode.item}
                            resourceTypes={resourceTypes}
                            relationTypes={relationTypes}
                            contributorTypes={contributorTypes}
                            submitting={submitting}
                            onCancel={() => setMode({ type: 'list' })}
                            onSubmit={(values) => {
                                if (mode.item.id) return handleUpdate(mode.item.id, values);
                            }}
                        />
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
