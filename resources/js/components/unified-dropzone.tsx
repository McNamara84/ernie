import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, ExternalLink, FileSpreadsheet, FileText, Upload, XCircle } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { UploadErrorModal } from '@/components/upload-error-modal';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { feedback } from '@/lib/feedback';
import { uploadIgsnCsv as uploadIgsnCsvRoute } from '@/routes/dashboard';
import { index as igsnIndexRoute } from '@/routes/igsns';
import { getUploadErrors, hasMultipleErrors, type UploadError, type UploadErrorResponse } from '@/types/upload';

type FileType = 'xml' | 'json' | 'csv' | 'unknown';
type UploadState = 'idle' | 'uploading' | 'success' | 'error';

/**
 * Legacy error format for backward compatibility.
 */
type LegacyUploadError = {
    row: number;
    igsn: string;
    message: string;
};

export type DataCiteUploadResult = {
    success: true;
    uploadKind: 'datacite';
    filename: string;
    resourceId?: string | null;
    sessionKey?: string | null;
    editorUrl: string;
    message?: string;
};

type CsvUploadResult = {
    success: boolean;
    created?: number;
    filename?: string;
    errors?: LegacyUploadError[] | UploadError[];
    error?: UploadError;
    message?: string;
};

type CsvUploadSuccessResult = CsvUploadResult & {
    success: true;
    uploadKind: 'csv';
    filename: string;
    created: number;
};

type UploadSuccessResult = DataCiteUploadResult | CsvUploadSuccessResult;

type UnifiedDropzoneProps = {
    onXmlUpload: (files: File[]) => Promise<DataCiteUploadResult | undefined>;
    onJsonUpload: (files: File[]) => Promise<DataCiteUploadResult | undefined>;
};

/**
 * Convert legacy error format to structured UploadError.
 */
function normalizeErrors(errors?: LegacyUploadError[] | UploadError[]): UploadError[] {
    if (!errors || errors.length === 0) return [];

    return errors.map((err) => {
        // Check if already in new format
        if ('category' in err && 'code' in err) {
            return err as UploadError;
        }
        // Convert legacy format
        const legacy = err as LegacyUploadError;
        return {
            category: 'data' as const,
            code: 'csv_error',
            message: legacy.message,
            row: legacy.row,
            identifier: legacy.igsn,
        };
    });
}

function requireDataCiteEditorTarget(result: DataCiteUploadResult | undefined, filename: string): DataCiteUploadResult {
    if (!result?.editorUrl || result.editorUrl.trim() === '') {
        throw new Error(`Upload completed for ${filename}, but no editor target was returned. Please try again or contact support.`);
    }

    return result;
}

export function UnifiedDropzone({ onXmlUpload, onJsonUpload }: UnifiedDropzoneProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [uploadState, setUploadState] = useState<UploadState>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [csvResult, setCsvResult] = useState<CsvUploadResult | null>(null);
    const [successResult, setSuccessResult] = useState<UploadSuccessResult | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [lastUploadType, setLastUploadType] = useState<FileType>('unknown');
    const [showErrorModal, setShowErrorModal] = useState(false);
    const [modalErrors, setModalErrors] = useState<UploadError[]>([]);
    const [modalFilename, setModalFilename] = useState<string>('');
    const [modalMessage, setModalMessage] = useState<string>('');

    const resetState = useCallback(() => {
        setUploadState('idle');
        setUploadProgress(0);
        setCsvResult(null);
        setSuccessResult(null);
        setSelectedFile(null);
        setError(null);
        setLastUploadType('unknown');
        setShowErrorModal(false);
        setModalErrors([]);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }, []);

    /**
     * Handle upload errors - show toast for simple errors, modal for complex ones.
     */
    const handleUploadError = useCallback((result: CsvUploadResult, filename: string) => {
        // Build error response compatible with our types
        const errorResponse: UploadErrorResponse = {
            success: false,
            message: result.message || 'Upload failed',
            filename,
            error: result.error,
            errors: result.errors as UploadError[] | undefined,
        };

        const errors = getUploadErrors(errorResponse);

        if (errors.length === 0) {
            // No structured errors, just show toast
            feedback.uploadFailed(filename, result.message || 'An error occurred during upload.');
        } else if (hasMultipleErrors(errorResponse, 3)) {
            // Many errors - show modal
            setModalErrors(normalizeErrors(result.errors));
            setModalFilename(filename);
            setModalMessage(result.message || 'Multiple errors occurred during upload.');
            setShowErrorModal(true);
        } else {
            // Few errors - show toast with details
            const errorMessages = errors.slice(0, 3).map((e) => {
                const prefix = e.row ? `Row ${e.row}: ` : '';
                return `${prefix}${e.message}`;
            });
            feedback.uploadFailed(filename, errorMessages.join('\n'));
        }
    }, []);

    const detectFileType = (file: File): FileType => {
        const name = file.name.toLowerCase();
        if (name.endsWith('.xml') || file.type === 'text/xml' || file.type === 'application/xml') {
            return 'xml';
        }
        if (name.endsWith('.json') || name.endsWith('.jsonld') || file.type === 'application/json' || file.type === 'application/ld+json') {
            return 'json';
        }
        if (name.endsWith('.csv') || name.endsWith('.txt') || file.type === 'text/csv') {
            return 'csv';
        }
        return 'unknown';
    };

    const uploadCsv = useCallback(
        async (file: File) => {
            setUploadState('uploading');
            setUploadProgress(10);
            setSelectedFile(file);
            setLastUploadType('csv');
            setSuccessResult(null);
            setError(null);

            const csrfHeaders = buildCsrfHeaders();

            // Accept either the unencrypted meta token (X-CSRF-TOKEN) or the
            // encrypted cookie token (X-XSRF-TOKEN); Laravel decrypts the latter.
            if (!csrfHeaders['X-CSRF-TOKEN'] && !csrfHeaders['X-XSRF-TOKEN']) {
                setUploadState('error');
                const errorResult: CsvUploadResult = {
                    success: false,
                    message: 'CSRF token not found. Please reload the page (Ctrl+F5) and try again.',
                };
                setCsvResult(errorResult);
                handleUploadError(errorResult, file.name);
                return;
            }

            const formData = new FormData();
            formData.append('file', file);

            try {
                setUploadProgress(30);

                const response = await fetch(uploadIgsnCsvRoute.url(), {
                    method: 'POST',
                    body: formData,
                    headers: csrfHeaders,
                    credentials: 'same-origin',
                });

                setUploadProgress(80);

                if (response.status === 419) {
                    window.location.reload();
                    throw new Error('Session expired. Reloading page...');
                }

                const data: CsvUploadResult = await response.json();
                setUploadProgress(100);

                if (data.success) {
                    const created = data.created ?? 0;
                    const success: CsvUploadSuccessResult = {
                        ...data,
                        success: true,
                        uploadKind: 'csv',
                        filename: data.filename ?? file.name,
                        created,
                    };

                    setUploadState('success');
                    setCsvResult(data);
                    setSuccessResult(success);
                    feedback.uploadSucceeded(file.name, `${created} IGSN(s) imported successfully.`);
                } else {
                    setUploadState('error');
                    setCsvResult(data);
                    handleUploadError(data, file.name);
                }
            } catch (err) {
                setUploadState('error');
                const errorResult: CsvUploadResult = {
                    success: false,
                    message: err instanceof Error ? err.message : 'Upload failed',
                };
                setCsvResult(errorResult);
                handleUploadError(errorResult, file.name);
            }
        },
        [handleUploadError],
    );

    const uploadXml = useCallback(
        async (file: File) => {
            setUploadState('uploading');
            setUploadProgress(50);
            setSelectedFile(file);
            setLastUploadType('xml');
            setError(null);
            setCsvResult(null);
            setSuccessResult(null);

            try {
                const result = requireDataCiteEditorTarget(await onXmlUpload([file]), file.name);
                setUploadProgress(100);
                setSuccessResult(result);
                setUploadState('success');
                feedback.uploadSucceeded(file.name, 'Draft metadata is ready to review from the upload panel.');
            } catch (err) {
                setUploadState('error');
                const errorMessage = err instanceof Error ? err.message : 'Upload failed';
                setError(errorMessage);

                feedback.uploadFailed(file.name, errorMessage);
            }
        },
        [onXmlUpload],
    );

    const uploadJson = useCallback(
        async (file: File) => {
            setUploadState('uploading');
            setUploadProgress(50);
            setSelectedFile(file);
            setLastUploadType('json');
            setError(null);
            setCsvResult(null);
            setSuccessResult(null);

            try {
                const result = requireDataCiteEditorTarget(await onJsonUpload([file]), file.name);
                setUploadProgress(100);
                setSuccessResult(result);
                setUploadState('success');
                feedback.uploadSucceeded(file.name, 'Draft metadata is ready to review from the upload panel.');
            } catch (err) {
                setUploadState('error');
                const errorMessage = err instanceof Error ? err.message : 'Upload failed';
                setError(errorMessage);

                feedback.uploadFailed(file.name, errorMessage);
            }
        },
        [onJsonUpload],
    );

    const handleFile = useCallback(
        async (file: File) => {
            const type = detectFileType(file);

            if (type === 'xml') {
                await uploadXml(file);
            } else if (type === 'json') {
                await uploadJson(file);
            } else if (type === 'csv') {
                await uploadCsv(file);
            } else {
                resetState();
                setSelectedFile(file);
                setUploadState('error');
                setError('Unsupported file type. Please upload an XML, JSON, or CSV file.');
            }
        },
        [uploadXml, uploadJson, uploadCsv, resetState],
    );

    const handleDragOver = (event: React.DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (event: React.DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        const related = event.relatedTarget as Node | null;
        if (!related || !event.currentTarget.contains(related)) {
            setIsDragging(false);
        }
    };

    const handleDrop = (event: React.DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
        const files = Array.from(event.dataTransfer.files);
        if (files.length > 0) {
            void handleFile(files[0]);
        }
    };

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const files = event.target.files;
        if (files && files.length > 0) {
            void handleFile(files[0]);
        }
    };

    // Render error state
    if (uploadState === 'error') {
        return (
            <div data-testid="dropzone-error-state" className="flex w-full flex-col items-center gap-4">
                <Alert variant="destructive" data-testid="dropzone-error-alert" className="max-w-2xl text-left">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>We couldn&apos;t import this file</AlertTitle>
                    <AlertDescription>
                        {selectedFile && <span className="font-medium">{selectedFile.name}: </span>}
                        {error || csvResult?.message || 'An error occurred during upload.'}
                    </AlertDescription>
                </Alert>

                {/* Show CSV-specific errors */}
                {lastUploadType === 'csv' && csvResult?.errors && csvResult.errors.length > 0 && (
                    <div className="max-h-60 w-full overflow-y-auto rounded-md border p-4">
                        <h4 className="mb-2 font-medium text-destructive">Row Errors:</h4>
                        <ul className="space-y-1 text-sm">
                            {normalizeErrors(csvResult.errors).map((err, index) => (
                                <li key={index} className="flex items-start gap-2">
                                    <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                                    <span>
                                        {err.row && <strong>Row {err.row}: </strong>}
                                        {err.identifier && <code className="mr-1 rounded bg-muted px-1">{err.identifier}</code>}
                                        {err.message}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <Button onClick={resetState} variant="outline">
                    Try Again
                </Button>
            </div>
        );
    }

    // Render uploading state
    if (uploadState === 'uploading') {
        return (
            <div data-testid="dropzone-uploading-state" className="flex w-full flex-col items-center gap-4" aria-busy="true">
                <Alert className="max-w-2xl text-left">
                    <Spinner size="sm" />
                    <AlertTitle>Import in progress</AlertTitle>
                    <AlertDescription>
                        Uploading {lastUploadType === 'csv' ? 'CSV' : lastUploadType === 'json' ? 'JSON' : 'XML'} metadata now. The result will appear
                        here when the import is ready.
                    </AlertDescription>
                </Alert>
                <div className="w-full max-w-md space-y-2">
                    <Progress value={uploadProgress} className="w-full" />
                    {selectedFile && <p className="text-sm text-muted-foreground">{selectedFile.name}</p>}
                </div>
            </div>
        );
    }

    // Render success state
    if (uploadState === 'success' && successResult) {
        const normalizedErrors = successResult.uploadKind === 'csv' ? normalizeErrors(successResult.errors) : [];

        return (
            <div data-testid="dropzone-success-state" className="flex w-full flex-col items-center gap-4">
                <Alert data-testid="dropzone-success-alert" className="max-w-2xl text-left">
                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                    <AlertTitle className="text-green-700">
                        {successResult.uploadKind === 'csv' ? 'IGSN import complete' : 'DataCite upload complete'}
                    </AlertTitle>
                    <AlertDescription>
                        {successResult.uploadKind === 'csv' ? (
                            <>
                                <span className="font-medium">{successResult.filename}</span> imported {successResult.created} IGSN resource(s). You
                                can upload another file or open the IGSN list.
                            </>
                        ) : (
                            <>
                                <span className="font-medium">{successResult.filename}</span> uploaded successfully.{' '}
                                {successResult.resourceId
                                    ? `Draft resource #${successResult.resourceId} is ready to review.`
                                    : 'The parsed metadata is ready to open in the editor.'}
                            </>
                        )}
                    </AlertDescription>
                </Alert>

                {normalizedErrors.length > 0 && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Some Rows Failed</AlertTitle>
                        <AlertDescription>{normalizedErrors.length} row(s) could not be processed.</AlertDescription>
                    </Alert>
                )}

                {normalizedErrors.length > 0 && (
                    <div className="max-h-60 w-full overflow-y-auto rounded-md border p-4">
                        <ul className="space-y-1 text-sm">
                            {normalizedErrors.map((err, index) => (
                                <li key={index} className="flex items-start gap-2">
                                    <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                                    <span>
                                        {err.row && <strong>Row {err.row}: </strong>}
                                        {err.identifier && <code className="mr-1 rounded bg-muted px-1">{err.identifier}</code>}
                                        {err.message}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="flex flex-wrap justify-center gap-2">
                    {successResult.uploadKind === 'datacite' && successResult.editorUrl && (
                        <Button type="button" onClick={() => router.visit(successResult.editorUrl)}>
                            <ExternalLink className="h-4 w-4" />
                            Open in editor
                        </Button>
                    )}
                    {successResult.uploadKind === 'csv' && (
                        <Button type="button" onClick={() => router.visit(igsnIndexRoute.url())}>
                            <ExternalLink className="h-4 w-4" />
                            View IGSNs
                        </Button>
                    )}
                    <Button type="button" onClick={resetState} variant="outline">
                        Upload another file
                    </Button>
                </div>
            </div>
        );
    }

    // Render idle state (dropzone)
    return (
        <>
            <div
                data-testid="unified-dropzone"
                onDrop={handleDrop}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                className={`flex w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed p-8 text-center transition-colors sm:p-10 ${
                    isDragging ? 'border-primary bg-accent/60 shadow-sm' : 'border-muted-foreground/25 bg-muted/60'
                }`}
            >
                <div className="mb-3 rounded-full border bg-background/80 px-3 py-1 text-xs font-medium tracking-[0.16em] text-muted-foreground uppercase">
                    Start from a file
                </div>
                <Upload className="mb-3 h-9 w-9 text-muted-foreground" />
                <p className="mb-2 text-base font-medium text-foreground">Drag &amp; drop files here</p>
                <p className="mb-4 max-w-lg text-sm leading-6 text-muted-foreground">
                    Import DataCite metadata or IGSN sample files without leaving the dashboard.
                </p>
                <div className="mb-5 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1 rounded-full bg-background/70 px-2.5 py-1">
                        <FileText className="h-3 w-3" /> DataCite (XML/JSON/JSON-LD)
                    </span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-background/70 px-2.5 py-1">
                        <FileSpreadsheet className="h-3 w-3" /> CSV (IGSN)
                    </span>
                </div>
                <input
                    ref={fileInputRef}
                    data-testid="unified-file-input"
                    type="file"
                    accept=".xml,.json,.jsonld,.csv,.txt"
                    className="hidden"
                    onChange={handleFileSelect}
                />
                <Button type="button" data-testid="unified-upload-button" onClick={() => fileInputRef.current?.click()}>
                    Browse Files
                </Button>
            </div>

            {/* Error modal for complex errors */}
            <UploadErrorModal
                open={showErrorModal}
                onClose={() => setShowErrorModal(false)}
                filename={modalFilename}
                message={modalMessage}
                errors={modalErrors}
                onRetry={resetState}
            />
        </>
    );
}
