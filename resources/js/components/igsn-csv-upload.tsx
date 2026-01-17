import { useCallback, useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { uploadIgsnCsv as uploadIgsnCsvRoute } from '@/routes/dashboard';
import { AlertCircle, CheckCircle2, FileSpreadsheet, Loader2, Upload, XCircle } from 'lucide-react';

type UploadState = 'idle' | 'uploading' | 'success' | 'error';

type UploadError = {
    row: number;
    igsn: string;
    message: string;
};

type UploadResult = {
    success: boolean;
    created?: number;
    errors?: UploadError[];
    message?: string;
};

export function IgsnCsvUpload() {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [uploadState, setUploadState] = useState<UploadState>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [result, setResult] = useState<UploadResult | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);

    const resetState = useCallback(() => {
        setUploadState('idle');
        setUploadProgress(0);
        setResult(null);
        setSelectedFile(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }, []);

    const uploadCsv = useCallback(async (file: File) => {
        setUploadState('uploading');
        setUploadProgress(10);
        setSelectedFile(file);

        const csrfHeaders = buildCsrfHeaders();
        const token = csrfHeaders['X-CSRF-TOKEN'];

        if (!token) {
            setUploadState('error');
            setResult({
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

            const data: UploadResult = await response.json();
            setUploadProgress(100);

            if (data.success) {
                setUploadState('success');
            } else {
                setUploadState('error');
            }
            setResult(data);
        } catch (error) {
            setUploadState('error');
            setResult({
                success: false,
                message: error instanceof Error ? error.message : 'Upload failed',
            });
        }
    }, []);

    const filterCsvFiles = (files: File[]): File[] => {
        return files.filter(
            (file) => file.type === 'text/csv' || file.name.endsWith('.csv') || file.name.endsWith('.txt')
        );
    };

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
        const csvFiles = filterCsvFiles(files);
        if (csvFiles.length > 0) {
            void uploadCsv(csvFiles[0]);
        }
    };

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const files = event.target.files;
        if (files && files.length > 0) {
            const csvFiles = filterCsvFiles(Array.from(files));
            if (csvFiles.length > 0) {
                void uploadCsv(csvFiles[0]);
            }
        }
    };

    return (
        <Card className="flex flex-col">
            <CardHeader className="items-center text-center">
                <CardTitle className="flex items-center gap-2">
                    <FileSpreadsheet className="h-5 w-5" />
                    IGSN CSV Upload
                </CardTitle>
                <CardDescription>
                    Upload a pipe-delimited CSV file containing IGSN sample metadata.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex w-full flex-col items-center gap-4">
                {/* Upload State: Idle */}
                {uploadState === 'idle' && (
                    <div
                        data-testid="igsn-dropzone"
                        onDrop={handleDrop}
                        onDragOver={handleDragOver}
                        onDragLeave={handleDragLeave}
                        className={`flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed p-8 text-center transition-colors ${
                            isDragging ? 'border-primary bg-accent' : 'border-muted-foreground/25 bg-muted'
                        }`}
                    >
                        <Upload className="mb-4 h-10 w-10 text-muted-foreground" />
                        <p className="mb-4 text-sm text-muted-foreground">
                            Drag &amp; drop a CSV file here, or click to select
                        </p>
                        <input
                            ref={fileInputRef}
                            data-testid="igsn-file-input"
                            type="file"
                            accept=".csv,.txt"
                            className="hidden"
                            onChange={handleFileSelect}
                        />
                        <Button
                            type="button"
                            data-testid="igsn-upload-button"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            Select CSV File
                        </Button>
                    </div>
                )}

                {/* Upload State: Uploading */}
                {uploadState === 'uploading' && (
                    <div className="flex w-full flex-col items-center gap-4 rounded-md border bg-muted/50 p-8">
                        <Loader2 className="h-10 w-10 animate-spin text-primary" />
                        <p className="text-sm font-medium">
                            Uploading {selectedFile?.name}...
                        </p>
                        <Progress value={uploadProgress} className="w-full max-w-md" />
                        <p className="text-xs text-muted-foreground">
                            Processing IGSN data...
                        </p>
                    </div>
                )}

                {/* Upload State: Success */}
                {uploadState === 'success' && result && (
                    <div className="flex w-full flex-col gap-4">
                        <Alert variant="default" className="border-green-500 bg-green-50 dark:bg-green-950/20">
                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                            <AlertTitle className="text-green-700 dark:text-green-400">
                                Upload Successful
                            </AlertTitle>
                            <AlertDescription className="text-green-600 dark:text-green-300">
                                {result.message || `${result.created} IGSN(s) imported successfully.`}
                            </AlertDescription>
                        </Alert>

                        {result.errors && result.errors.length > 0 && (
                            <Alert variant="default" className="border-yellow-500 bg-yellow-50 dark:bg-yellow-950/20">
                                <AlertCircle className="h-4 w-4 text-yellow-600" />
                                <AlertTitle className="text-yellow-700 dark:text-yellow-400">
                                    Warnings
                                </AlertTitle>
                                <AlertDescription>
                                    <ul className="mt-2 list-inside list-disc space-y-1 text-sm text-yellow-600 dark:text-yellow-300">
                                        {result.errors.map((err, idx) => (
                                            <li key={idx}>
                                                Row {err.row} ({err.igsn}): {err.message}
                                            </li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        )}

                        <Button onClick={resetState} variant="outline" className="self-center">
                            Upload Another File
                        </Button>
                    </div>
                )}

                {/* Upload State: Error */}
                {uploadState === 'error' && result && (
                    <div className="flex w-full flex-col gap-4">
                        <Alert variant="destructive">
                            <XCircle className="h-4 w-4" />
                            <AlertTitle>Upload Failed</AlertTitle>
                            <AlertDescription>
                                {result.message || 'An error occurred during upload.'}
                            </AlertDescription>
                        </Alert>

                        {result.errors && result.errors.length > 0 && (
                            <div className="max-h-48 overflow-y-auto rounded-md border bg-destructive/10 p-4">
                                <p className="mb-2 text-sm font-medium text-destructive">
                                    Errors ({result.errors.length}):
                                </p>
                                <ul className="list-inside list-disc space-y-1 text-sm text-destructive">
                                    {result.errors.slice(0, 10).map((err, idx) => (
                                        <li key={idx}>
                                            Row {err.row} ({err.igsn}): {err.message}
                                        </li>
                                    ))}
                                    {result.errors.length > 10 && (
                                        <li className="text-muted-foreground">
                                            ... and {result.errors.length - 10} more errors
                                        </li>
                                    )}
                                </ul>
                            </div>
                        )}

                        <Button onClick={resetState} variant="outline" className="self-center">
                            Try Again
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
