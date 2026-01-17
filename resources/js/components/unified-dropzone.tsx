import { AlertCircle, CheckCircle2, FileSpreadsheet, FileText, Loader2, Upload, XCircle } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { uploadIgsnCsv as uploadIgsnCsvRoute } from '@/routes/dashboard';

type FileType = 'xml' | 'csv' | 'unknown';
type UploadState = 'idle' | 'uploading' | 'success' | 'error';

type UploadError = {
    row: number;
    igsn: string;
    message: string;
};

type CsvUploadResult = {
    success: boolean;
    created?: number;
    errors?: UploadError[];
    message?: string;
};

type UnifiedDropzoneProps = {
    onXmlUpload: (files: File[]) => Promise<void>;
};

export function UnifiedDropzone({ onXmlUpload }: UnifiedDropzoneProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [uploadState, setUploadState] = useState<UploadState>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [csvResult, setCsvResult] = useState<CsvUploadResult | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [lastUploadType, setLastUploadType] = useState<FileType>('unknown');

    const resetState = useCallback(() => {
        setUploadState('idle');
        setUploadProgress(0);
        setCsvResult(null);
        setSelectedFile(null);
        setError(null);
        setLastUploadType('unknown');
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
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

    const uploadCsv = useCallback(async (file: File) => {
        setUploadState('uploading');
        setUploadProgress(10);
        setSelectedFile(file);
        setLastUploadType('csv');

        const csrfHeaders = buildCsrfHeaders();
        const token = csrfHeaders['X-CSRF-TOKEN'];

        if (!token) {
            setUploadState('error');
            setCsvResult({
                success: false,
                message: 'CSRF token not found. Please reload the page (Ctrl+F5) and try again.',
            });
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
            } else {
                setUploadState('error');
            }
            setCsvResult(data);
        } catch (err) {
            setUploadState('error');
            setCsvResult({
                success: false,
                message: err instanceof Error ? err.message : 'Upload failed',
            });
        }
    }, []);

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
                setError(err instanceof Error ? err.message : 'Upload failed');
            }
        },
        [onXmlUpload]
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
        [uploadXml, uploadCsv]
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
            <div className="flex w-full flex-col items-center gap-4">
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Upload Error</AlertTitle>
                    <AlertDescription>
                        {error || csvResult?.message || 'An error occurred during upload.'}
                    </AlertDescription>
                </Alert>

                {/* Show CSV-specific errors */}
                {csvResult?.errors && csvResult.errors.length > 0 && (
                    <div className="w-full max-h-60 overflow-y-auto rounded-md border p-4">
                        <h4 className="mb-2 font-medium text-destructive">Row Errors:</h4>
                        <ul className="space-y-1 text-sm">
                            {csvResult.errors.map((err, index) => (
                                <li key={index} className="flex items-start gap-2">
                                    <XCircle className="h-4 w-4 mt-0.5 shrink-0 text-destructive" />
                                    <span>
                                        <strong>Row {err.row}</strong> ({err.igsn}): {err.message}
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
            <div className="flex w-full flex-col items-center gap-4">
                <div className="flex items-center gap-2 text-muted-foreground">
                    <Loader2 className="h-5 w-5 animate-spin" />
                    <span>
                        Uploading {lastUploadType === 'csv' ? 'CSV' : 'XML'} file...
                    </span>
                </div>
                <Progress value={uploadProgress} className="w-full max-w-xs" />
                {selectedFile && (
                    <p className="text-sm text-muted-foreground">{selectedFile.name}</p>
                )}
            </div>
        );
    }

    // Render success state (only for CSV, XML navigates to editor)
    if (uploadState === 'success' && csvResult) {
        return (
            <div className="flex w-full flex-col items-center gap-4">
                <Alert>
                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                    <AlertTitle className="text-green-700">Upload Successful</AlertTitle>
                    <AlertDescription>
                        Successfully created {csvResult.created} IGSN resource(s).
                    </AlertDescription>
                </Alert>

                {csvResult.errors && csvResult.errors.length > 0 && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Some Rows Failed</AlertTitle>
                        <AlertDescription>
                            {csvResult.errors.length} row(s) could not be processed.
                        </AlertDescription>
                    </Alert>
                )}

                {csvResult.errors && csvResult.errors.length > 0 && (
                    <div className="w-full max-h-60 overflow-y-auto rounded-md border p-4">
                        <ul className="space-y-1 text-sm">
                            {csvResult.errors.map((err, index) => (
                                <li key={index} className="flex items-start gap-2">
                                    <XCircle className="h-4 w-4 mt-0.5 shrink-0 text-destructive" />
                                    <span>
                                        <strong>Row {err.row}</strong> ({err.igsn}): {err.message}
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
            <p className="mb-2 text-sm font-medium text-foreground">
                Drag &amp; drop files here
            </p>
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
            <Button
                type="button"
                data-testid="unified-upload-button"
                onClick={() => fileInputRef.current?.click()}
            >
                Browse Files
            </Button>
        </div>
    );
}
