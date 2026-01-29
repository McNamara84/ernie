import { Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface BulkActionsToolbarProps {
    selectedCount: number;
    onDelete: () => void;
    canDelete: boolean;
    isDeleting?: boolean;
}

/**
 * Toolbar component for bulk actions on IGSN list.
 *
 * Designed for extensibility - future actions (export, status change)
 * can be added as additional buttons.
 */
export function BulkActionsToolbar({ selectedCount, onDelete, canDelete, isDeleting = false }: BulkActionsToolbarProps) {
    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="flex items-center justify-between rounded-lg border bg-muted/50 px-4 py-2">
            <span className="text-sm text-muted-foreground">
                {selectedCount} {selectedCount === 1 ? 'item' : 'items'} selected
            </span>
            <div className="flex items-center gap-2">
                {/* Future actions can be added here */}
                {/* Example: <Button variant="outline" size="sm">Export JSON</Button> */}

                {canDelete && (
                    <Button variant="destructive" size="sm" onClick={onDelete} disabled={isDeleting}>
                        <Trash2 className="mr-2 size-4" />
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </Button>
                )}
            </div>
        </div>
    );
}
