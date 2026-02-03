import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, FileSpreadsheet, FileText, Upload, XCircle } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { UploadErrorModal } from '@/components/upload-error-modal';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { uploadIgsnCsv as uploadIgsnCsvRoute } from '@/routes/dashboard';
import { index as igsnIndexRoute } from '@/routes/igsns';
import { getUploadErrors, hasMultipleErrors, type UploadError, type UploadErrorResponse } from '@/types/upload';

type FileType = 'xml' | 'csv' | 'unknown';
type UploadState = 'idle' | 'uploading' | 'success' | 'error';

/**
 * Legacy error format for backward compatibility.
 */
type LegacyUploadError = {
    row: number;
    igsn: string;
    message: string;
};

type CsvUploadResult = {
    success: boolean;
    created?: number;
    filename?: string;
    errors?: LegacyUploadError[] | UploadError[];
    error?: UploadError;
    message?: string;
};

type UnifiedDropzoneProps = {
    onXmlUpload: (files: File[]) => Promise<void>;
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

export function UnifiedDropzone({ onXmlUpload }: UnifiedDropzoneProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [uploadState, setUploadState] = useState<UploadState>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [csvResult, setCsvResult] = useState<CsvUploadResult | null>(null);
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
            toast.error(`Upload failed: ${filename}`, {
                description: result.message || 'An error occurred during upload.',
                duration: 8000,
            });
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
            toast.error(`Upload failed: ${filename}`, {
                description: errorMessages.join('\n'),
                duration: 10000,
            });
        }
    }, []);

    const detectFileType = (file: File): FileType => {
        const name = file.name.toLowerCase();
        if (name.endsWith('.xml') || file.type === 'text/xml' || file.type === 'application/xml') {
            return 'xml';
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

            const csrfHeaders = buildCsrfHeaders();
            const token = csrfHeaders['X-CSRF-TOKEN'];

            if (!token) {
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
                    setUploadState('success');
                    setCsvResult(data);
                    toast.success('Upload successful', {
                        description: `${data.created} IGSN(s) imported from ${file.name}`,
                    });
                    // Redirect to IGSN list after short delay to show success message
                    setTimeout(() => {
                        router.visit(igsnIndexRoute.url());
                    }, 1500);
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

            try {
                await onXmlUpload([file]);
                // If successful, the page will navigate to the editor
                // so we don't need to handle success state here
            } catch (err) {
                setUploadState('error');
                const errorMessage = err instanceof Error ? err.message : 'Upload failed';
                setError(errorMessage);

                // Show toast notification for XML upload errors
                toast.error(`Upload failed: ${file.name}`, {
                    description: errorMessage,
                    duration: 8000,
                });
            }
        },
        [onXmlUpload],
    );

    const handleFile = useCallback(
        async (file: File) => {
            const type = detectFileType(file);

            if (type === 'xml') {
                await uploadXml(file);
            } else if (type === 'csv') {
                await uploadCsv(file);
            } else {
                setUploadState('error');
                setError('Unsupported file type. Please upload an XML or CSV file.');
            }
        },
        [uploadXml, uploadCsv],
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
                <Alert variant="destructive" data-testid="dropzone-error-alert">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Upload Error</AlertTitle>
                    <AlertDescription>
                        {selectedFile && <span className="font-medium">{selectedFile.name}: </span>}
                        {error || csvResult?.message || 'An error occurred during upload.'}
                    </AlertDescription>
                </Alert>

                {/* Show CSV-specific errors */}
                {csvResult?.errors && csvResult.errors.length > 0 && (
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
            <div data-testid="dropzone-uploading-state" className="flex w-full flex-col items-center gap-4">
                <div className="flex items-center gap-2 text-muted-foreground">
                    <Spinner size="md" />
                    <span>Uploading {lastUploadType === 'csv' ? 'CSV' : 'XML'} file...</span>
                </div>
                <Progress value={uploadProgress} className="w-full max-w-xs" />
                {selectedFile && <p className="text-sm text-muted-foreground">{selectedFile.name}</p>}
            </div>
        );
    }

    // Render success state (only for CSV, XML navigates to editor)
    if (uploadState === 'success' && csvResult) {
        const normalizedErrors = normalizeErrors(csvResult.errors);

        return (
            <div data-testid="dropzone-success-state" className="flex w-full flex-col items-center gap-4">
                <Alert data-testid="dropzone-success-alert">
                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                    <AlertTitle className="text-green-700">Upload Successful</AlertTitle>
                    <AlertDescription>Successfully created {csvResult.created} IGSN resource(s).</AlertDescription>
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

                <Button onClick={resetState} variant="outline">
                    Upload Another File
                </Button>
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
                className={`flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed p-12 text-center transition-colors ${
                    isDragging ? 'border-primary bg-accent' : 'border-muted-foreground/25 bg-muted'
                }`}
            >
                <Upload className="mb-4 h-10 w-10 text-muted-foreground" />
                <p className="mb-2 text-sm font-medium text-foreground">Drag &amp; drop files here</p>
                <p className="mb-4 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <FileText className="h-3 w-3" /> XML (DataCite)
                    </span>
                    {' or '}
                    <span className="inline-flex items-center gap-1">
                        <FileSpreadsheet className="h-3 w-3" /> CSV (IGSN)
                    </span>
                </p>
                <input
                    ref={fileInputRef}
                    data-testid="unified-file-input"
                    type="file"
                    accept=".xml,.csv,.txt"
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
