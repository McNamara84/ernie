import { AlertCircle, FileWarning, Server, XCircle } from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { type UploadError, type UploadErrorCategory } from '@/types/upload';

interface UploadErrorModalProps {
    /** Whether the modal is open */
    open: boolean;
    /** Callback when the modal should close */
    onClose: () => void;
    /** Name of the file that failed to upload */
    filename: string;
    /** Summary error message */
    message: string;
    /** Array of structured errors */
    errors: UploadError[];
    /** Optional callback to retry the upload */
    onRetry?: () => void;
}

/**
 * Get icon for error category.
 */
function getCategoryIcon(category: UploadErrorCategory) {
    switch (category) {
        case 'validation':
            return <FileWarning className="h-4 w-4 text-yellow-600" />;
        case 'data':
            return <AlertCircle className="h-4 w-4 text-orange-600" />;
        case 'server':
            return <Server className="h-4 w-4 text-red-600" />;
        default:
            return <XCircle className="h-4 w-4 text-destructive" />;
    }
}

/**
 * Get human-readable category label.
 */
function getCategoryLabel(category: UploadErrorCategory): string {
    switch (category) {
        case 'validation':
            return 'Validation Errors';
        case 'data':
            return 'Data Errors';
        case 'server':
            return 'Server Errors';
        default:
            return 'Errors';
    }
}

/**
 * Modal dialog for displaying complex upload errors.
 *
 * Shows errors grouped by category with details about row numbers
 * and identifiers when applicable (for CSV uploads).
 */
export function UploadErrorModal({ open, onClose, filename, message, errors, onRetry }: UploadErrorModalProps) {
    // Group errors by category
    const groupedErrors = useMemo(() => {
        return errors.reduce(
            (acc, err) => {
                const category = err.category || 'data';
                if (!acc[category]) {
                    acc[category] = [];
                }
                acc[category].push(err);
                return acc;
            },
            {} as Record<UploadErrorCategory, UploadError[]>,
        );
    }, [errors]);

    // Get category order for consistent display
    const categoryOrder: UploadErrorCategory[] = ['validation', 'data', 'server'];
    const sortedCategories = categoryOrder.filter((cat) => groupedErrors[cat]?.length > 0);

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent className="max-w-2xl" data-testid="upload-error-modal">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-destructive">
                        <AlertCircle className="h-5 w-5" />
                        Upload Failed
                    </DialogTitle>
                    <DialogDescription className="break-all">
                        <span className="font-medium">{filename}</span>: {message}
                    </DialogDescription>
                </DialogHeader>

                <ScrollArea className="max-h-96 pr-4">
                    <div className="space-y-6">
                        {sortedCategories.map((category) => (
                            <div key={category} className="space-y-2">
                                <h4 className="flex items-center gap-2 font-medium text-sm">
                                    {getCategoryIcon(category)}
                                    {getCategoryLabel(category)}
                                    <span className="text-muted-foreground">({groupedErrors[category].length})</span>
                                </h4>
                                <ul className="space-y-2 pl-6">
                                    {groupedErrors[category].map((err, index) => (
                                        <li key={`${category}-${index}`} className="flex items-start gap-2 text-sm">
                                            <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                                            <span>
                                                {err.row && (
                                                    <span className="font-medium text-muted-foreground">Row {err.row}: </span>
                                                )}
                                                {err.identifier && (
                                                    <code className="mr-1 rounded bg-muted px-1 py-0.5 text-xs">{err.identifier}</code>
                                                )}
                                                {err.message}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </ScrollArea>

                <DialogFooter className="gap-2 sm:gap-0">
                    {onRetry && (
                        <Button variant="outline" onClick={onRetry}>
                            Try Again
                        </Button>
                    )}
                    <Button onClick={onClose}>Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
