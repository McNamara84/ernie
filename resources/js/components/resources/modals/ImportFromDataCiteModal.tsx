import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertCircle, CheckCircle2, Download, XCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { buildCsrfHeaders } from '@/lib/csrf-token';

interface ImportProgress {
    status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
    total: number;
    processed: number;
    imported: number;
    skipped: number;
    failed: number;
    enriched?: number;
    skipped_dois: string[];
    enriched_dois?: string[];
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
    const hasNotifiedSuccessRef = useRef(false);

    useEffect(() => {
        if (!isOpen) {
            setModalState('confirm');
            setImportId(null);
            setProgress(null);
            setIsStarting(false);
            setError(null);
            setShowSkippedDois(false);
            setShowFailedDois(false);
            hasNotifiedSuccessRef.current = false;
        }
    }, [isOpen]);

    useEffect(() => {
        const changedCount = (progress?.imported ?? 0) + (progress?.enriched ?? 0);

        if (!isOpen || modalState !== 'completed' || changedCount < 1 || hasNotifiedSuccessRef.current) {
            return;
        }

        hasNotifiedSuccessRef.current = true;
        onSuccess?.();
    }, [isOpen, modalState, onSuccess, progress?.enriched, progress?.imported]);

    useEffect(() => {
        if (!importId || modalState !== 'running') {
            return;
        }

        let isCancelled = false;
        let timeoutId: ReturnType<typeof setTimeout> | undefined;
        const startTime = Date.now();

        const poll = async () => {
            if (isCancelled) {
                return;
            }

            try {
                const response = await axios.get<ImportProgress>(`/datacite/import/${importId}/status`);

                if (isCancelled) {
                    return;
                }

                setProgress(response.data);

                if (response.data.status === 'completed') {
                    setModalState('completed');
                    return;
                }

                if (response.data.status === 'failed' || response.data.status === 'cancelled') {
                    setModalState('failed');
                    setError(response.data.error || 'Import failed');
                    return;
                }

                const elapsed = Date.now() - startTime;
                timeoutId = setTimeout(poll, elapsed < 60000 ? 2000 : 5000);
            } catch (err) {
                console.error('Failed to fetch import status:', err);
                if (!isCancelled) {
                    const elapsed = Date.now() - startTime;
                    timeoutId = setTimeout(poll, elapsed < 60000 ? 2000 : 5000);
                }
            }
        };

        void poll();

        return () => {
            isCancelled = true;
            if (timeoutId !== undefined) {
                clearTimeout(timeoutId);
            }
        };
    }, [importId, modalState]);

    const startImport = useCallback(async () => {
        setIsStarting(true);
        setError(null);
        hasNotifiedSuccessRef.current = false;

        try {
            const response = await axios.post<{ import_id: string; message: string }>('/datacite/import/start', {}, { headers: buildCsrfHeaders() });

            setImportId(response.data.import_id);
            setModalState('running');
            setProgress({
                status: 'pending',
                total: 0,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                enriched_dois: [],
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
                    toast.error('Session expired', {
                        description: 'Reloading page to refresh session...',
                    });
                    setTimeout(() => router.reload(), 1500);
                    return;
                }

                if (err.response?.status === 403) {
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
        onClose();
    }, [onClose]);

    const progressPercent = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
    const enrichedCount = progress?.enriched ?? 0;

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

    const formatRemainingTime = (startedAt?: string, processed?: number, total?: number): string => {
        if (!startedAt || !processed || !total || processed === 0) return '';

        const start = new Date(startedAt);
        const now = new Date();
        const elapsedSeconds = (now.getTime() - start.getTime()) / 1000;
        const rate = processed / elapsedSeconds;

        if (rate === 0) return '';

        const remaining = total - processed;
        const remainingSeconds = Math.round(remaining / rate);

        if (remainingSeconds < 60) return `~${remainingSeconds}s remaining`;
        const minutes = Math.floor(remainingSeconds / 60);
        const secs = remainingSeconds % 60;
        return `~${minutes}m ${secs}s remaining`;
    };

    const summaryCards = progress ? (
        <div className="grid grid-cols-4 gap-4 text-center">
            <div className="rounded-lg bg-green-50 p-3 dark:bg-green-950">
                <div className="text-2xl font-bold text-green-600 dark:text-green-400">{progress.imported}</div>
                <div className="text-xs text-muted-foreground">Imported</div>
            </div>
            <div className="rounded-lg bg-yellow-50 p-3 dark:bg-yellow-950">
                <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{progress.skipped}</div>
                <div className="text-xs text-muted-foreground">Skipped</div>
            </div>
            <div className="rounded-lg bg-blue-50 p-3 dark:bg-blue-950">
                <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">{enrichedCount}</div>
                <div className="text-xs text-muted-foreground">Updated</div>
            </div>
            <div className="rounded-lg bg-red-50 p-3 dark:bg-red-950">
                <div className="text-2xl font-bold text-red-600 dark:text-red-400">{progress.failed}</div>
                <div className="text-xs text-muted-foreground">Failed</div>
            </div>
        </div>
    ) : null;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        Import all old Resources
                    </DialogTitle>
                    <DialogDescription>
                        {modalState === 'confirm' && 'Import all registered GFZ legacy resources from the DataCite production API into ERNIE.'}
                        {modalState === 'running' && 'Import is in progress...'}
                        {modalState === 'completed' && 'Import completed successfully.'}
                        {modalState === 'failed' && 'Import failed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4">
                    {modalState === 'confirm' && (
                        <div className="space-y-4">
                            <Alert>
                                <Download className="size-4" />
                                <AlertTitle>What will happen?</AlertTitle>
                                <AlertDescription>
                                    <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
                                        <li>All DOIs registered with your GFZ DataCite credentials will be fetched</li>
                                        <li>DOIs already in ERNIE will not be overwritten</li>
                                        <li>Missing legacy download links can be added to existing Resources</li>
                                        <li>New legacy DOIs will be imported as Resources</li>
                                        <li>You will see a summary of imported, updated, skipped, and failed DOIs after completion</li>
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

                    {modalState === 'running' && progress && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="flex items-center gap-2">
                                        <Spinner size="sm" />
                                        Processing...
                                    </span>
                                    <span className="text-muted-foreground">
                                        {progress.processed} / {progress.total || '?'} DOIs
                                    </span>
                                </div>
                                <Progress value={progressPercent} className="h-2" />
                            </div>

                            {summaryCards}

                            {progress.started_at && (
                                <div className="text-center text-xs text-muted-foreground">
                                    <p>Elapsed: {formatDuration(progress.started_at)}</p>
                                    {progress.processed > 0 && progress.total > 0 && progress.processed < progress.total && (
                                        <p className="mt-1 text-primary">
                                            {formatRemainingTime(progress.started_at, progress.processed, progress.total)}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {modalState === 'completed' && progress && (
                        <div className="space-y-4">
                            <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                <CheckCircle2 className="size-4 text-green-600 dark:text-green-400" />
                                <AlertTitle className="text-green-800 dark:text-green-200">Import Complete</AlertTitle>
                                <AlertDescription className="text-green-700 dark:text-green-300">
                                    Imported {progress.imported} resources and updated {enrichedCount} existing resources in{' '}
                                    {formatDuration(progress.started_at, progress.completed_at)}.
                                </AlertDescription>
                            </Alert>

                            {summaryCards}

                            {progress.skipped > 0 && (
                                <Collapsible open={showSkippedDois} onOpenChange={setShowSkippedDois}>
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="w-full justify-between">
                                            <span>Skipped DOIs (already exist)</span>
                                            <span className="text-muted-foreground">{showSkippedDois ? 'Hide' : 'Show'}</span>
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

                            {progress.failed > 0 && (
                                <Collapsible open={showFailedDois} onOpenChange={setShowFailedDois}>
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="w-full justify-between text-red-600 dark:text-red-400">
                                            <span>Failed DOIs</span>
                                            <span>{showFailedDois ? 'Hide' : 'Show'}</span>
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
                                        <Spinner size="sm" className="mr-2" />
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
                            <Spinner size="sm" className="mr-2" />
                            Import in progress...
                        </Button>
                    )}

                    {(modalState === 'completed' || modalState === 'failed') && (
                        <Button variant="outline" onClick={handleClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
