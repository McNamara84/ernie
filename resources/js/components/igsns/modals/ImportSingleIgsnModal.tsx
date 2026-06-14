import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertCircle, CheckCircle2, ChevronDown, ChevronUp, Download, XCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { normalizeIgsnInput } from '@/lib/igsn-validation';

interface ImportProgress {
    status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
    total: number;
    processed: number;
    imported: number;
    skipped: number;
    failed: number;
    enriched: number;
    skipped_dois: string[];
    failed_dois: Array<{ doi: string; error: string }>;
    requested_igsn?: string;
    discovered_children?: string[];
    started_at?: string;
    completed_at?: string;
    error?: string;
}

interface SingleIgsnImportErrorResponse {
    message?: string;
    errors?: {
        igsn?: string[];
    };
}

interface ImportSingleIgsnModalProps {
    isOpen: boolean;
    igsnPrefix?: string;
    onClose: () => void;
    onSuccess?: () => void;
}

type ModalState = 'confirm' | 'running' | 'completed' | 'cancelled' | 'failed';

export default function ImportSingleIgsnModal({ isOpen, igsnPrefix = '10.60510', onClose, onSuccess }: ImportSingleIgsnModalProps) {
    const [igsnInput, setIgsnInput] = useState('');
    const [submittedIgsn, setSubmittedIgsn] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | null>(null);
    const [modalState, setModalState] = useState<ModalState>('confirm');
    const [importId, setImportId] = useState<string | null>(null);
    const [progress, setProgress] = useState<ImportProgress | null>(null);
    const [isStarting, setIsStarting] = useState(false);
    const [isCancelling, setIsCancelling] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showSkippedDois, setShowSkippedDois] = useState(false);
    const [showFailedDois, setShowFailedDois] = useState(false);
    const hasNotifiedSuccessRef = useRef(false);

    useEffect(() => {
        if (!isOpen) {
            setIgsnInput('');
            setSubmittedIgsn(null);
            setFieldError(null);
            setModalState('confirm');
            setImportId(null);
            setProgress(null);
            setIsStarting(false);
            setIsCancelling(false);
            setError(null);
            setShowSkippedDois(false);
            setShowFailedDois(false);
            hasNotifiedSuccessRef.current = false;
        }
    }, [isOpen]);

    useEffect(() => {
        if (
            !isOpen ||
            !['completed', 'cancelled'].includes(modalState) ||
            (progress?.imported ?? 0) < 1 ||
            hasNotifiedSuccessRef.current
        ) {
            return;
        }

        hasNotifiedSuccessRef.current = true;
        onSuccess?.();
    }, [isOpen, modalState, onSuccess, progress?.imported]);

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
                const response = await axios.get<ImportProgress>(`/igsns/import/${importId}/status`);

                if (isCancelled) {
                    return;
                }

                setProgress(response.data);

                if (response.data.status === 'completed') {
                    setModalState('completed');
                    return;
                }

                if (response.data.status === 'cancelled') {
                    setModalState('cancelled');
                    return;
                }

                if (response.data.status === 'failed') {
                    setModalState('failed');
                    setError(response.data.error || response.data.failed_dois[0]?.error || 'Import failed');
                    return;
                }

                const elapsed = Date.now() - startTime;
                timeoutId = setTimeout(poll, elapsed < 60000 ? 2000 : 5000);
            } catch (pollError) {
                console.error('Failed to fetch single IGSN import status:', pollError);
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
        const normalized = normalizeIgsnInput(igsnInput, igsnPrefix);

        if (!normalized.isValid || !normalized.handle) {
            setFieldError(normalized.message ?? 'Enter a valid IGSN.');
            return;
        }

        setIsStarting(true);
        setFieldError(null);
        setError(null);
        setSubmittedIgsn(normalized.handle);
        setIgsnInput(normalized.handle);
        hasNotifiedSuccessRef.current = false;

        try {
            const response = await axios.post<{ import_id: string; message: string }>(
                '/igsns/import/start-single',
                { igsn: normalized.handle },
                { headers: buildCsrfHeaders() },
            );

            setImportId(response.data.import_id);
            setModalState('running');
            setProgress({
                status: 'pending',
                total: 1,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: normalized.handle,
                discovered_children: [],
            });

            toast.info('Single IGSN import started', {
                description: `Importing ${normalized.handle}...`,
            });
        } catch (requestError) {
            console.error('Failed to start single IGSN import:', requestError);

            let errorMessage = 'Failed to start single IGSN import.';
            let nextFieldError: string | null = null;

            if (isAxiosError(requestError)) {
                if (requestError.response?.status === 419) {
                    toast.error('Session expired', {
                        description: 'Reloading page to refresh session...',
                    });
                    setTimeout(() => router.reload(), 1500);
                    return;
                }

                if (requestError.response?.status === 403) {
                    errorMessage = 'You do not have permission to import IGSNs.';
                } else {
                    const responseData = requestError.response?.data as SingleIgsnImportErrorResponse | undefined;
                    nextFieldError = responseData?.errors?.igsn?.[0] ?? null;
                    errorMessage = nextFieldError ?? responseData?.message ?? requestError.message;
                }
            }

            setFieldError(nextFieldError);
            setError(errorMessage);
            toast.error(errorMessage);
        } finally {
            setIsStarting(false);
        }
    }, [igsnInput, igsnPrefix]);

    const cancelImport = useCallback(async () => {
        if (!importId || isCancelling) {
            return;
        }

        setIsCancelling(true);
        try {
            await axios.post(`/igsns/import/${importId}/cancel`, {}, { headers: buildCsrfHeaders() });
            toast.info('Import cancellation requested');
        } catch (cancelError) {
            console.error('Failed to cancel single IGSN import:', cancelError);
            toast.error('Failed to cancel import');
        } finally {
            setIsCancelling(false);
        }
    }, [importId, isCancelling]);

    const handleClose = useCallback(() => {
        if (modalState === 'running') {
            return;
        }

        onClose();
    }, [modalState, onClose]);

    const progressPercent = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
    const childCount = progress?.discovered_children?.length ?? 0;
    const isAlreadyImported = progress?.imported === 0 && progress?.skipped === progress?.total && progress?.failed === 0;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Download className="size-5" />
                        Import single IGSN
                    </DialogTitle>
                    <DialogDescription>
                        {modalState === 'confirm' && 'Enter one IGSN to import it from DataCite into ERNIE.'}
                        {modalState === 'running' && `Importing ${submittedIgsn ?? 'IGSN'}...`}
                        {modalState === 'completed' && (isAlreadyImported ? 'This IGSN already exists in ERNIE.' : 'Import completed successfully.')}
                        {modalState === 'cancelled' && 'Import was cancelled.'}
                        {modalState === 'failed' && 'Single IGSN import failed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4">
                    {modalState === 'confirm' && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="single-import-igsn">IGSN</Label>
                                <Input
                                    id="single-import-igsn"
                                    value={igsnInput}
                                    onChange={(event) => {
                                        setIgsnInput(event.target.value);
                                        if (fieldError) {
                                            setFieldError(null);
                                        }
                                    }}
                                    placeholder="ICDP5052EUYY001"
                                    autoComplete="off"
                                    aria-invalid={fieldError ? true : undefined}
                                />
                                {fieldError && <p className="text-sm text-destructive">{fieldError}</p>}
                            </div>

                            {error && !fieldError && (
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
                                        {progress.processed} / {progress.total || '?'} IGSNs
                                    </span>
                                </div>
                                <Progress value={progressPercent} className="h-2" />
                            </div>

                            {childCount > 0 && (
                                <Alert>
                                    <AlertCircle className="size-4" />
                                    <AlertTitle>Parent IGSN detected</AlertTitle>
                                    <AlertDescription>
                                        {childCount} direct child {childCount === 1 ? 'IGSN was' : 'IGSNs were'} discovered and added to this import.
                                    </AlertDescription>
                                </Alert>
                            )}
                        </div>
                    )}

                    {(modalState === 'completed' || modalState === 'cancelled') && progress && (
                        <div className="space-y-4">
                            <Alert className={isAlreadyImported ? undefined : 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950'}>
                                {isAlreadyImported ? <AlertCircle className="size-4" /> : <CheckCircle2 className="size-4 text-green-600 dark:text-green-400" />}
                                <AlertTitle>
                                    {modalState === 'cancelled' ? 'Import cancelled' : isAlreadyImported ? 'Already imported' : 'Import complete'}
                                </AlertTitle>
                                <AlertDescription>
                                    {modalState === 'cancelled'
                                        ? `Import stopped after processing ${progress.processed} of ${progress.total || '?'} IGSNs.`
                                        : isAlreadyImported
                                          ? `${submittedIgsn ?? progress.requested_igsn ?? 'This IGSN'} already exists in ERNIE.`
                                          : `${submittedIgsn ?? progress.requested_igsn ?? 'The IGSN'} import finished with ${progress.imported} imported and ${progress.enriched} enriched.`}
                                </AlertDescription>
                            </Alert>

                            {childCount > 0 && (
                                <div className="rounded-md border p-3 text-sm">
                                    <div className="font-medium">Discovered child IGSNs</div>
                                    <div className="mt-1 text-muted-foreground">
                                        {childCount} direct child {childCount === 1 ? 'IGSN' : 'IGSNs'} included.
                                    </div>
                                </div>
                            )}

                            <div className="grid grid-cols-4 gap-3 text-center">
                                <div className="rounded-lg bg-green-50 p-3 dark:bg-green-950">
                                    <div className="text-2xl font-bold text-green-600 dark:text-green-400">{progress.imported}</div>
                                    <div className="text-xs text-muted-foreground">Imported</div>
                                </div>
                                <div className="rounded-lg bg-blue-50 p-3 dark:bg-blue-950">
                                    <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">{progress.enriched}</div>
                                    <div className="text-xs text-muted-foreground">Enriched</div>
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

                            {progress.skipped > 0 && (
                                <Collapsible open={showSkippedDois} onOpenChange={setShowSkippedDois}>
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="w-full justify-between">
                                            <span>Skipped IGSNs</span>
                                            {showSkippedDois ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
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
                                            <span>Failed IGSNs</span>
                                            {showFailedDois ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <div className="mt-2 max-h-40 overflow-y-auto rounded-md border border-red-200 bg-red-50/50 p-2 dark:border-red-800 dark:bg-red-950/50">
                                            {progress.failed_dois.map(({ doi, error: failureError }, index) => (
                                                <div key={`${index}-${doi}`} className="border-b border-red-100 py-2 last:border-0 dark:border-red-900">
                                                    <div className="font-mono text-xs">{doi}</div>
                                                    <div className="text-xs text-red-600 dark:text-red-400">{failureError}</div>
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
                            <AlertTitle>Import failed</AlertTitle>
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
                            <LoadingButton onClick={startImport} loading={isStarting} disabled={igsnInput.trim().length === 0}>
                                {!isStarting && <Download className="size-4" />}
                                {isStarting ? 'Starting...' : 'Start Import'}
                            </LoadingButton>
                        </>
                    )}

                    {modalState === 'running' && (
                        <Button variant="destructive" onClick={cancelImport} disabled={isCancelling}>
                            {isCancelling ? (
                                <>
                                    <Spinner size="sm" className="mr-2" />
                                    Cancelling...
                                </>
                            ) : (
                                'Cancel Import'
                            )}
                        </Button>
                    )}

                    {(modalState === 'completed' || modalState === 'cancelled' || modalState === 'failed') && (
                        <Button variant="outline" onClick={handleClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
