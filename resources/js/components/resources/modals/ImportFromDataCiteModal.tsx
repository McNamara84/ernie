import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertCircle, CheckCircle2, Download, Loader2, XCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { withBasePath } from '@/lib/base-path';
import { buildCsrfHeaders } from '@/lib/csrf-token';

interface ImportProgress {
    status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
    total: number;
    processed: number;
    imported: number;
    skipped: number;
    failed: number;
    skipped_dois: string[];
    failed_dois: Array<{ doi: string; error: string }>;
    started_at?: string;
    completed_at?: string;
    error?: string;
}

interface ImportFromDataCiteModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: () => void;
}

type ModalState = 'confirm' | 'running' | 'completed' | 'failed';

export default function ImportFromDataCiteModal({ isOpen, onClose, onSuccess }: ImportFromDataCiteModalProps) {
    const [modalState, setModalState] = useState<ModalState>('confirm');
    const [importId, setImportId] = useState<string | null>(null);
    const [progress, setProgress] = useState<ImportProgress | null>(null);
    const [isStarting, setIsStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showSkippedDois, setShowSkippedDois] = useState(false);
    const [showFailedDois, setShowFailedDois] = useState(false);

    // Reset state when modal opens/closes
    useEffect(() => {
        if (!isOpen) {
            setModalState('confirm');
            setImportId(null);
            setProgress(null);
            setIsStarting(false);
            setError(null);
            setShowSkippedDois(false);
            setShowFailedDois(false);
        }
    }, [isOpen]);

    // Poll for progress updates with adaptive interval using setTimeout chains.
    // This approach avoids race conditions that can occur with setInterval + clearInterval,
    // as each poll schedules the next one only after completion.
    useEffect(() => {
        if (!importId || modalState !== 'running') {
            return;
        }

        let isCancelled = false;
        let timeoutId: ReturnType<typeof setTimeout>;
        const startTime = Date.now();

        const poll = async () => {
            if (isCancelled) return;

            try {
                const response = await axios.get<ImportProgress>(withBasePath(`/datacite/import/${importId}/status`));

                if (isCancelled) return; // Check again after async operation

                setProgress(response.data);

                if (response.data.status === 'completed') {
                    setModalState('completed');
                    return; // Stop polling
                } else if (response.data.status === 'failed' || response.data.status === 'cancelled') {
                    setModalState('failed');
                    setError(response.data.error || 'Import failed');
                    return; // Stop polling
                }

                // Schedule next poll with adaptive delay:
                // - 2s for the first 60 seconds
                // - 5s afterward to reduce server load during long imports
                const elapsed = Date.now() - startTime;
                const delay = elapsed < 60000 ? 2000 : 5000;
                timeoutId = setTimeout(poll, delay);
            } catch (err) {
                console.error('Failed to fetch import status:', err);
                // Continue polling even on error, with the current delay
                if (!isCancelled) {
                    const elapsed = Date.now() - startTime;
                    const delay = elapsed < 60000 ? 2000 : 5000;
                    timeoutId = setTimeout(poll, delay);
                }
            }
        };

        // Start polling immediately
        poll();

        return () => {
            isCancelled = true;
            clearTimeout(timeoutId);
        };
    }, [importId, modalState]);

    const startImport = useCallback(async () => {
        setIsStarting(true);
        setError(null);

        try {
            const response = await axios.post<{ import_id: string; message: string }>(
                withBasePath('/datacite/import/start'),
                {},
                { headers: buildCsrfHeaders() }
            );

            setImportId(response.data.import_id);
            setModalState('running');
            setProgress({
                status: 'pending',
                total: 0,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
            });

            toast.info('Import started', {
                description: 'Fetching DOIs from DataCite...',
            });
        } catch (err) {
            console.error('Failed to start import:', err);

            let errorMessage = 'Failed to start import';
            if (isAxiosError(err)) {
                if (err.response?.status === 419) {
                    // CSRF token mismatch - reload page to get fresh token
                    toast.error('Session expired', {
                        description: 'Reloading page to refresh session...',
                    });
                    setTimeout(() => router.reload(), 1500);
                    return;
                } else if (err.response?.status === 403) {
                    errorMessage = 'You do not have permission to import from DataCite.';
                } else {
                    errorMessage = err.response?.data?.message || err.message;
                }
            }

            setError(errorMessage);
            toast.error(errorMessage);
        } finally {
            setIsStarting(false);
        }
    }, []);

    const handleClose = useCallback(() => {
        if (modalState === 'completed') {
            // Call onSuccess callback to refresh the resources list.
            // Note: The onSuccess callback (from resources.tsx) already handles
            // page reload, so we don't call router.reload() here to avoid duplicates.
            if (onSuccess) {
                onSuccess();
            }
        }
        onClose();
    }, [modalState, onClose, onSuccess]);

    const progressPercent = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;

    const formatDuration = (startedAt?: string, completedAt?: string): string => {
        if (!startedAt) return '';

        const start = new Date(startedAt);
        const end = completedAt ? new Date(completedAt) : new Date();
        const seconds = Math.round((end.getTime() - start.getTime()) / 1000);

        if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        return `${minutes}m ${remainingSeconds}s`;
    };

    /**
     * Calculate estimated remaining time based on current processing rate.
     *
     * Note: This estimate assumes uniform processing time per DOI. In practice,
     * skipped DOIs are processed faster than imported ones, so the estimate may
     * be less accurate when there are many skipped DOIs. For simplicity, we use
     * an average rate rather than tracking imported vs skipped times separately.
     */
    const formatRemainingTime = (startedAt?: string, processed?: number, total?: number): string => {
        if (!startedAt || !processed || !total || processed === 0) return '';

        const start = new Date(startedAt);
        const now = new Date();
        const elapsedSeconds = (now.getTime() - start.getTime()) / 1000;

        // Calculate rate (DOIs per second)
        const rate = processed / elapsedSeconds;
        if (rate === 0) return '';

        // Calculate remaining time
        const remaining = total - processed;
        const remainingSeconds = Math.round(remaining / rate);

        if (remainingSeconds < 60) return `~${remainingSeconds}s remaining`;
        const minutes = Math.floor(remainingSeconds / 60);
        const secs = remainingSeconds % 60;
        return `~${minutes}m ${secs}s remaining`;
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        Import from DataCite
                    </DialogTitle>
                    <DialogDescription>
                        {modalState === 'confirm' && 'Import all registered DOIs from the DataCite production API into ERNIE.'}
                        {modalState === 'running' && 'Import is in progress...'}
                        {modalState === 'completed' && 'Import completed successfully.'}
                        {modalState === 'failed' && 'Import failed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4">
                    {/* Confirmation State */}
                    {modalState === 'confirm' && (
                        <div className="space-y-4">
                            <Alert>
                                <Download className="size-4" />
                                <AlertTitle>What will happen?</AlertTitle>
                                <AlertDescription>
                                    <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
                                        <li>All DOIs registered with your DataCite credentials will be fetched</li>
                                        <li>DOIs already in ERNIE will be skipped (not overwritten)</li>
                                        <li>New DOIs will be imported as Resources</li>
                                        <li>You will see a summary of skipped DOIs after completion</li>
                                    </ul>
                                </AlertDescription>
                            </Alert>

                            {error && (
                                <Alert variant="destructive">
                                    <AlertCircle className="size-4" />
                                    <AlertTitle>Error</AlertTitle>
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            )}
                        </div>
                    )}

                    {/* Running State */}
                    {modalState === 'running' && progress && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="flex items-center gap-2">
                                        <Loader2 className="size-4 animate-spin" />
                                        Processing...
                                    </span>
                                    <span className="text-muted-foreground">
                                        {progress.processed} / {progress.total || '?'} DOIs
                                    </span>
                                </div>
                                <Progress value={progressPercent} className="h-2" />
                            </div>

                            <div className="grid grid-cols-3 gap-4 text-center">
                                <div className="rounded-lg bg-green-50 p-3 dark:bg-green-950">
                                    <div className="text-2xl font-bold text-green-600 dark:text-green-400">{progress.imported}</div>
                                    <div className="text-xs text-muted-foreground">Imported</div>
                                </div>
                                <div className="rounded-lg bg-yellow-50 p-3 dark:bg-yellow-950">
                                    <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{progress.skipped}</div>
                                    <div className="text-xs text-muted-foreground">Skipped</div>
                                </div>
                                <div className="rounded-lg bg-red-50 p-3 dark:bg-red-950">
                                    <div className="text-2xl font-bold text-red-600 dark:text-red-400">{progress.failed}</div>
                                    <div className="text-xs text-muted-foreground">Failed</div>
                                </div>
                            </div>

                            {progress.started_at && (
                                <div className="text-center text-xs text-muted-foreground">
                                    <p>Elapsed: {formatDuration(progress.started_at)}</p>
                                    {progress.processed > 0 && progress.total > 0 && progress.processed < progress.total && (
                                        <p className="mt-1 text-primary">{formatRemainingTime(progress.started_at, progress.processed, progress.total)}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Completed State */}
                    {modalState === 'completed' && progress && (
                        <div className="space-y-4">
                            <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                <CheckCircle2 className="size-4 text-green-600 dark:text-green-400" />
                                <AlertTitle className="text-green-800 dark:text-green-200">Import Complete</AlertTitle>
                                <AlertDescription className="text-green-700 dark:text-green-300">
                                    Successfully imported {progress.imported} resources in{' '}
                                    {formatDuration(progress.started_at, progress.completed_at)}.
                                </AlertDescription>
                            </Alert>

                            <div className="grid grid-cols-3 gap-4 text-center">
                                <div className="rounded-lg bg-green-50 p-3 dark:bg-green-950">
                                    <div className="text-2xl font-bold text-green-600 dark:text-green-400">{progress.imported}</div>
                                    <div className="text-xs text-muted-foreground">Imported</div>
                                </div>
                                <div className="rounded-lg bg-yellow-50 p-3 dark:bg-yellow-950">
                                    <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{progress.skipped}</div>
                                    <div className="text-xs text-muted-foreground">Skipped</div>
                                </div>
                                <div className="rounded-lg bg-red-50 p-3 dark:bg-red-950">
                                    <div className="text-2xl font-bold text-red-600 dark:text-red-400">{progress.failed}</div>
                                    <div className="text-xs text-muted-foreground">Failed</div>
                                </div>
                            </div>

                            {/* Skipped DOIs */}
                            {progress.skipped > 0 && (
                                <Collapsible open={showSkippedDois} onOpenChange={setShowSkippedDois}>
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="w-full justify-between">
                                            <span>Skipped DOIs (already exist)</span>
                                            <span className="text-muted-foreground">{showSkippedDois ? '▲' : '▼'}</span>
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div className="mt-2 max-h-40 overflow-y-auto rounded-md border bg-muted/50 p-2">
                                            {progress.skipped_dois.map((doi) => (
                                                <div key={doi} className="py-1 font-mono text-xs">
                                                    {doi}
                                                </div>
                                            ))}
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            )}

                            {/* Failed DOIs */}
                            {progress.failed > 0 && (
                                <Collapsible open={showFailedDois} onOpenChange={setShowFailedDois}>
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="w-full justify-between text-red-600 dark:text-red-400">
                                            <span>Failed DOIs</span>
                                            <span>{showFailedDois ? '▲' : '▼'}</span>
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div className="mt-2 max-h-40 overflow-y-auto rounded-md border border-red-200 bg-red-50/50 p-2 dark:border-red-800 dark:bg-red-950/50">
                                            {progress.failed_dois.map(({ doi, error }) => (
                                                <div key={doi} className="border-b border-red-100 py-2 last:border-0 dark:border-red-900">
                                                    <div className="font-mono text-xs">{doi}</div>
                                                    <div className="text-xs text-red-600 dark:text-red-400">{error}</div>
                                                </div>
                                            ))}
                                        </div>
                                    </CollapsibleContent>
                                </Collapsible>
                            )}
                        </div>
                    )}

                    {/* Failed State */}
                    {modalState === 'failed' && (
                        <Alert variant="destructive">
                            <XCircle className="size-4" />
                            <AlertTitle>Import Failed</AlertTitle>
                            <AlertDescription>{error || 'An unknown error occurred during the import.'}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    {modalState === 'confirm' && (
                        <>
                            <Button variant="outline" onClick={handleClose}>
                                Cancel
                            </Button>
                            <Button onClick={startImport} disabled={isStarting}>
                                {isStarting ? (
                                    <>
                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                        Starting...
                                    </>
                                ) : (
                                    <>
                                        <Download className="mr-2 size-4" />
                                        Start Import
                                    </>
                                )}
                            </Button>
                        </>
                    )}

                    {modalState === 'running' && (
                        <Button variant="outline" disabled>
                            <Loader2 className="mr-2 size-4 animate-spin" />
                            Import in progress...
                        </Button>
                    )}

                    {(modalState === 'completed' || modalState === 'failed') && (
                        <Button onClick={handleClose}>Close</Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
