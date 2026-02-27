import { CloudUpload, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

interface BulkActionsToolbarProps {
    selectedCount: number;
    onDelete: () => void;
    onRegister?: () => void;
    canDelete: boolean;
    isDeleting?: boolean;
    isRegistering?: boolean;
}

/**
 * Toolbar component for bulk actions on IGSN list.
 *
 * Provides bulk delete and bulk registration actions.
 */
export function BulkActionsToolbar({ selectedCount, onDelete, onRegister, canDelete, isDeleting = false, isRegistering = false }: BulkActionsToolbarProps) {
    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="flex items-center justify-between rounded-lg border bg-muted/50 px-4 py-2">
            <span className="text-sm text-muted-foreground">
                {selectedCount} {selectedCount === 1 ? 'item' : 'items'} selected
            </span>
            <div className="flex items-center gap-2">
                {onRegister && (
                <Button variant="default" size="sm" onClick={onRegister} disabled={isRegistering || isDeleting}>
                    {isRegistering ? (
                        <>
                            <Spinner size="sm" className="mr-2" />
                            Registering...
                        </>
                    ) : (
                        <>
                            <CloudUpload className="mr-2 size-4" />
                            Register Selected
                        </>
                    )}
                </Button>
                )}

                {canDelete && (
                    <Button variant="destructive" size="sm" onClick={onDelete} disabled={isDeleting || isRegistering}>
                        <Trash2 className="mr-2 size-4" />
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </Button>
                )}
            </div>
        </div>
    );
}
