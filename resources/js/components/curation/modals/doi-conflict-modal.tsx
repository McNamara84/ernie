import { AlertTriangle, CheckCircle2, ClipboardCopy, ExternalLink } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface DoiConflictModalProps {
    /** Whether the modal is open */
    open: boolean;
    /** Callback when the modal open state changes */
    onOpenChange: (open: boolean) => void;
    /** The DOI that already exists in the database */
    existingDoi: string;
    /** The title of the existing resource (optional) */
    existingResourceTitle?: string;
    /** The ID of the existing resource (optional, for linking) */
    existingResourceId?: number;
    /** The last globally assigned DOI */
    lastAssignedDoi: string;
    /** The suggested next available DOI */
    suggestedDoi: string;
    /** Callback when user chooses to use the suggested DOI */
    onUseSuggested?: (doi: string) => void;
}

/**
 * Modal displayed when a user enters a DOI that already exists in the database.
 * Shows the conflict details, last assigned DOI, and a suggested alternative.
 */
export function DoiConflictModal({
    open,
    onOpenChange,
    existingDoi,
    existingResourceTitle,
    existingResourceId,
    lastAssignedDoi,
    suggestedDoi,
    onUseSuggested,
}: DoiConflictModalProps) {
    const [copiedField, setCopiedField] = useState<'suggested' | 'last' | null>(null);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Cleanup timeout on unmount or when copiedField changes
    useEffect(() => {
        return () => {
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        };
    }, []);

    const copyToClipboard = useCallback(async (text: string, field: 'suggested' | 'last') => {
        try {
            await navigator.clipboard.writeText(text);
            setCopiedField(field);
            toast.success('DOI in die Zwischenablage kopiert');
            
            // Clear any existing timeout before setting a new one
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
            
            // Reset the copied state after 2 seconds
            copyTimeoutRef.current = setTimeout(() => {
                setCopiedField(null);
            }, 2000);
        } catch (error) {
            // Log the error for debugging (clipboard access may be denied due to permissions)
            console.error('Failed to copy to clipboard:', error);
            toast.error('Kopieren fehlgeschlagen');
        }
    }, []);

    const handleUseSuggested = useCallback(() => {
        onUseSuggested?.(suggestedDoi);
        onOpenChange(false);
    }, [suggestedDoi, onUseSuggested, onOpenChange]);

    const handleClose = useCallback(() => {
        onOpenChange(false);
    }, [onOpenChange]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-destructive">
                        <AlertTriangle className="h-5 w-5" aria-hidden="true" />
                        DOI bereits vergeben
                    </DialogTitle>
                    <DialogDescription>
                        Die eingegebene DOI ist bereits in der Datenbank registriert.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {/* Existing DOI Info */}
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                        <div className="mb-2 text-sm font-medium text-destructive">
                            Konflikt-DOI:
                        </div>
                        <code className="block rounded bg-muted px-2 py-1 font-mono text-sm">
                            {existingDoi}
                        </code>
                        {existingResourceTitle && (
                            <div className="mt-2 text-sm text-muted-foreground">
                                <span className="font-medium">Zugehörige Resource:</span>{' '}
                                {existingResourceId ? (
                                    <a
                                        href={`/resources/${existingResourceId}/edit`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 text-primary hover:underline"
                                    >
                                        {existingResourceTitle}
                                        <ExternalLink className="h-3 w-3" aria-hidden="true" />
                                    </a>
                                ) : (
                                    existingResourceTitle
                                )}
                            </div>
                        )}
                    </div>

                    {/* Last Assigned DOI */}
                    <div className="space-y-2">
                        <div className="text-sm font-medium">
                            Zuletzt vergebene DOI:
                        </div>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 rounded bg-muted px-2 py-1 font-mono text-sm">
                                {lastAssignedDoi}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => copyToClipboard(lastAssignedDoi, 'last')}
                                className={cn(
                                    'shrink-0 transition-colors',
                                    copiedField === 'last' && 'border-green-500 text-green-600'
                                )}
                                aria-label="Zuletzt vergebene DOI kopieren"
                            >
                                {copiedField === 'last' ? (
                                    <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                                ) : (
                                    <ClipboardCopy className="h-4 w-4" aria-hidden="true" />
                                )}
                            </Button>
                        </div>
                    </div>

                    {/* Suggested DOI */}
                    <div className="space-y-2">
                        <div className="text-sm font-medium text-primary">
                            Vorgeschlagene DOI:
                        </div>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 rounded bg-primary/10 px-2 py-1 font-mono text-sm font-medium text-primary">
                                {suggestedDoi}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => copyToClipboard(suggestedDoi, 'suggested')}
                                className={cn(
                                    'shrink-0 transition-colors',
                                    copiedField === 'suggested' && 'border-green-500 text-green-600'
                                )}
                                aria-label="Vorgeschlagene DOI kopieren"
                            >
                                {copiedField === 'suggested' ? (
                                    <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                                ) : (
                                    <ClipboardCopy className="h-4 w-4" aria-hidden="true" />
                                )}
                            </Button>
                        </div>
                    </div>
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" onClick={handleClose}>
                        Schließen
                    </Button>
                    {onUseSuggested && (
                        <Button type="button" onClick={handleUseSuggested}>
                            Vorschlag übernehmen
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default DoiConflictModal;
