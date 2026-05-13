import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertCircle, CheckCircle2, Download, Search, XCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { normalizeDOI, validateDOIFormat } from '@/lib/doi-validation';

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

interface SingleImportErrorResponse {
    message?: string;
    errors?: {
        doi?: string[];
    };
}

interface ImportSingleOldResourceModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: () => void;
}

type ModalState = 'confirm' | 'running' | 'completed' | 'failed';

export default function ImportSingleOldResourceModal({ isOpen, onClose, onSuccess }: ImportSingleOldResourceModalProps) {
    const [doiInput, setDoiInput] = useState('');
    const [submittedDoi, setSubmittedDoi] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | null>(null);
    const [modalState, setModalState] = useState<ModalState>('confirm');
    const [importId, setImportId] = useState<string | null>(null);
    const [progress, setProgress] = useState<ImportProgress | null>(null);
    const [isStarting, setIsStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!isOpen) {
            setDoiInput('');
            setSubmittedDoi(null);
            setFieldError(null);
            setModalState('confirm');
            setImportId(null);
            setProgress(null);
            setIsStarting(false);
            setError(null);
        }
    }, [isOpen]);

    useEffect(() => {
        if (!importId || modalState !== 'running') {
            return;
        }

        let isCancelled = false;
        let timeoutId: ReturnType<typeof setTimeout>;
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
                    setError(response.data.error || response.data.failed_dois[0]?.error || 'Import failed');
                    return;
                }

                const elapsed = Date.now() - startTime;
                timeoutId = setTimeout(poll, elapsed < 60000 ? 2000 : 5000);
            } catch (pollError) {
                console.error('Failed to fetch single import status:', pollError);
                if (!isCancelled) {
                    const elapsed = Date.now() - startTime;
                    timeoutId = setTimeout(poll, elapsed < 60000 ? 2000 : 5000);
                }
            }
        };

        void poll();

        return () => {
            isCancelled = true;
            clearTimeout(timeoutId);
        };
    }, [importId, modalState]);

    const startImport = useCallback(async () => {
        const normalizedDoi = normalizeDOI(doiInput);
        const validation = validateDOIFormat(normalizedDoi);

        if (!validation.isValid) {
            setFieldError(validation.message ?? 'Enter a valid DOI.');
            return;
        }

        setIsStarting(true);
        setFieldError(null);
        setError(null);
        setSubmittedDoi(normalizedDoi);
        setDoiInput(normalizedDoi);

        try {
            const response = await axios.post<{ import_id: string; message: string }>(
                '/datacite/import/start-single',
                { doi: normalizedDoi },
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
                skipped_dois: [],
                failed_dois: [],
            });

            toast.info('Single import started', {
                description: `Importing ${normalizedDoi}...`,
            });
        } catch (requestError) {
            console.error('Failed to start single import:', requestError);

            let errorMessage = 'Failed to start single import.';
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
                    errorMessage = 'You do not have permission to import from DataCite.';
                } else {
                    const responseData = requestError.response?.data as SingleImportErrorResponse | undefined;
                    nextFieldError = responseData?.errors?.doi?.[0] ?? null;
                    errorMessage = nextFieldError ?? responseData?.message ?? requestError.message;
                }
            }

            setFieldError(nextFieldError);
            setError(errorMessage);
            toast.error(errorMessage);
        } finally {
            setIsStarting(false);
        }
    }, [doiInput]);

    const handleClose = useCallback(() => {
        if (modalState === 'completed' && progress?.imported === 1) {
            onSuccess?.();
        }

        onClose();
    }, [modalState, onClose, onSuccess, progress?.imported]);

    const progressPercent = progress && progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
    const isAlreadyImported = progress?.skipped === 1;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        Import old single Resource
                    </DialogTitle>
                    <DialogDescription>
                        {modalState === 'confirm' && 'Enter a single GFZ legacy DOI to import it from DataCite into ERNIE.'}
                        {modalState === 'running' && `Importing ${submittedDoi ?? 'resource'}...`}
                        {modalState === 'completed' && (isAlreadyImported ? 'This resource already exists in ERNIE.' : 'Import completed successfully.')}
                        {modalState === 'failed' && 'Single resource import failed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4">
                    {modalState === 'confirm' && (
                        <div className="space-y-4">
                            <Alert>
                                <Search className="size-4" />
                                <AlertTitle>Accepted DOI formats</AlertTitle>
                                <AlertDescription>
                                    Enter either a bare DOI such as <span className="font-mono">10.5880/GFZ.OJSJ.2026.001</span> or a DOI URL such as{' '}
                                    <span className="font-mono">https://doi.org/10.5880/GFZ.OJSJ.2026.001</span>.
                                </AlertDescription>
                            </Alert>

                            <div className="space-y-2">
                                <Label htmlFor="single-import-doi">DOI</Label>
                                <Input
                                    id="single-import-doi"
                                    value={doiInput}
                                    onChange={(event) => {
                                        setDoiInput(event.target.value);
                                        if (fieldError) {
                                            setFieldError(null);
                                        }
                                    }}
                                    placeholder="10.5880/GFZ.OJSJ.2026.001"
                                    autoComplete="off"
                                    aria-invalid={fieldError ? true : undefined}
                                />
                                <p className="text-xs text-muted-foreground">
                                    The DOI must exist in the GFZ legacy database before the import can start.
                                </p>
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
                                        Processing {submittedDoi ?? 'resource'}...
                                    </span>
                                    <span className="text-muted-foreground">
                                        {progress.processed} / {progress.total} resource
                                    </span>
                                </div>
                                <Progress value={progressPercent} className="h-2" />
                            </div>
                        </div>
                    )}

                    {modalState === 'completed' && progress && (
                        <Alert className={isAlreadyImported ? undefined : 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950'}>
                            {isAlreadyImported ? <AlertCircle className="size-4" /> : <CheckCircle2 className="size-4 text-green-600 dark:text-green-400" />}
                            <AlertTitle>{isAlreadyImported ? 'Already imported' : 'Import complete'}</AlertTitle>
                            <AlertDescription>
                                {isAlreadyImported
                                    ? `${submittedDoi ?? 'This DOI'} already exists in ERNIE and was not imported again.`
                                    : `${submittedDoi ?? 'The DOI'} was imported successfully.`}
                            </AlertDescription>
                        </Alert>
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
                            <Button onClick={startImport} disabled={isStarting || doiInput.trim().length === 0}>
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