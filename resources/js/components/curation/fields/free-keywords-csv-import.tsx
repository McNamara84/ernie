/**
 * FreeKeywordsCsvImport Component
 *
 * CSV bulk import for free keywords:
 * - Drag & drop or file selection
 * - Validation with detailed error reporting
 * - Preview of parsed keywords with duplicate detection
 * - Example CSV template download
 *
 * Based on author-csv-import.tsx design pattern
 */

import { FileUp, Info, Upload, X } from 'lucide-react';
import Papa from 'papaparse';
import { useCallback, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';

interface CsvRow {
    [key: string]: string;
}

interface ValidationError {
    row: number;
    field: string;
    value: string;
    message: string;
}

interface FreeKeywordsCsvImportProps {
    onImport: (keywords: string[]) => void;
    onClose: () => void;
    existingKeywords: string[];
}

/**
 * Normalize keyword for comparison (lowercase + trim)
 */
function normalizeKeyword(keyword: string): string {
    return keyword.trim().toLowerCase();
}

/**
 * FreeKeywordsCsvImport Component
 */
export default function FreeKeywordsCsvImport({ onImport, onClose, existingKeywords }: FreeKeywordsCsvImportProps) {
    const [file, setFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [progress, setProgress] = useState(0);
    const [errors, setErrors] = useState<ValidationError[]>([]);
    const [parsedData, setParsedData] = useState<string[]>([]);
    const [duplicatesRemoved, setDuplicatesRemoved] = useState(0);
    const [alreadyExisting, setAlreadyExisting] = useState(0);

    const parseCSV = useCallback(
        async (csvFile: File) => {
            setIsProcessing(true);
            setErrors([]);
            setParsedData([]);
            setDuplicatesRemoved(0);
            setAlreadyExisting(0);
            setProgress(0);

            try {
                const text = await csvFile.text();

                // Parse CSV with PapaParse
                Papa.parse<CsvRow>(text, {
                    header: true,
                    skipEmptyLines: true,
                    complete: (results) => {
                        if (results.data.length === 0) {
                            setErrors([
                                {
                                    row: 0,
                                    field: 'file',
                                    value: '',
                                    message: 'CSV file is empty or has no data rows',
                                },
                            ]);
                            setIsProcessing(false);
                            return;
                        }

                        // Check if we have too many keywords
                        if (results.data.length > 1000) {
                            setErrors([
                                {
                                    row: 0,
                                    field: 'file',
                                    value: '',
                                    message: `Too many keywords. Maximum is 1000, found ${results.data.length}`,
                                },
                            ]);
                            setIsProcessing(false);
                            return;
                        }

                        const validationErrors: ValidationError[] = [];
                        const rawKeywords: string[] = [];
                        const seenNormalized = new Set<string>();

                        // Normalize existing keywords for comparison
                        const existingNormalized = new Set(existingKeywords.map(normalizeKeyword));

                        results.data.forEach((row, index) => {
                            const rowNum = index + 2; // +1 for header, +1 for 1-based

                            // Find the "Keyword" column (case-insensitive)
                            const keywordField = Object.keys(row).find((k) => k.toLowerCase().includes('keyword')) || Object.keys(row)[0]; // Fallback to first column

                            if (!keywordField) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'keyword',
                                    value: '',
                                    message: 'No keyword column found',
                                });
                                return;
                            }

                            const keyword = row[keywordField]?.trim();

                            // Validation: Empty keyword
                            if (!keyword) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'keyword',
                                    value: '',
                                    message: 'Keyword cannot be empty',
                                });
                                return;
                            }

                            // Check if keyword is too long (reasonable limit)
                            if (keyword.length > 255) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'keyword',
                                    value: keyword.substring(0, 50) + '...',
                                    message: 'Keyword is too long (max 255 characters)',
                                });
                                return;
                            }

                            const normalized = normalizeKeyword(keyword);

                            // Track duplicates within CSV
                            if (seenNormalized.has(normalized)) {
                                // Don't add to validationErrors, just skip
                                setDuplicatesRemoved((prev) => prev + 1);
                                return;
                            }

                            seenNormalized.add(normalized);
                            rawKeywords.push(keyword);

                            setProgress(Math.round(((index + 1) / results.data.length) * 100));
                        });

                        // Count how many keywords already exist
                        const newKeywords = rawKeywords.filter((keyword) => {
                            const normalized = normalizeKeyword(keyword);
                            const exists = existingNormalized.has(normalized);
                            if (exists) {
                                setAlreadyExisting((prev) => prev + 1);
                            }
                            return !exists;
                        });

                        setErrors(validationErrors);
                        setParsedData(newKeywords);
                        setProgress(100);
                        setIsProcessing(false);
                    },
                    error: (error: Error) => {
                        setErrors([
                            {
                                row: 0,
                                field: 'file',
                                value: '',
                                message: error.message || 'Failed to parse CSV file',
                            },
                        ]);
                        setIsProcessing(false);
                    },
                });
            } catch (error) {
                setErrors([
                    {
                        row: 0,
                        field: 'file',
                        value: '',
                        message: error instanceof Error ? error.message : 'Failed to parse CSV file',
                    },
                ]);
                setIsProcessing(false);
            }
        },
        [existingKeywords],
    );

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const selectedFile = event.target.files?.[0];
        if (selectedFile && selectedFile.type === 'text/csv') {
            setFile(selectedFile);
            parseCSV(selectedFile);
        }
    };

    const handleDragOver = (event: React.DragEvent) => {
        event.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleDrop = (event: React.DragEvent) => {
        event.preventDefault();
        setIsDragging(false);

        const droppedFile = event.dataTransfer.files[0];
        if (droppedFile && droppedFile.type === 'text/csv') {
            setFile(droppedFile);
            parseCSV(droppedFile);
        }
    };

    const handleImport = () => {
        if (parsedData.length > 0 && errors.length === 0) {
            onImport(parsedData);
            onClose();
        }
    };

    const downloadExample = () => {
        const exampleCSV = `Keyword
climate change
temperature
precipitation
seismology
geophysics
remote sensing
hydrology
geochemistry
paleomagnetism
crustal deformation`;

        const blob = new Blob([exampleCSV], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'free-keywords-example.csv';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="space-y-1">
                    <Label className="text-base font-semibold">CSV Bulk Import</Label>
                    <p className="text-sm text-muted-foreground">Import multiple free keywords from a CSV file</p>
                </div>
                <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close CSV import">
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {/* Example Download */}
            <Alert>
                <Info className="h-4 w-4" />
                <AlertDescription className="flex items-center justify-between">
                    <span className="text-sm">Need a template? Download our example CSV file</span>
                    <Button variant="outline" size="sm" onClick={downloadExample}>
                        <FileUp className="mr-2 h-3 w-3" />
                        Download Example
                    </Button>
                </AlertDescription>
            </Alert>

            {/* File Upload Area */}
            <div
                className={`relative rounded-lg border-2 border-dashed p-8 text-center transition-colors ${isDragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'} ${file ? 'bg-muted/50' : ''} `}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
            >
                <input type="file" id="csv-upload-free-keywords" accept=".csv,text/csv" onChange={handleFileSelect} className="sr-only" />

                {!file ? (
                    <label htmlFor="csv-upload-free-keywords" className="flex cursor-pointer flex-col items-center gap-2">
                        <Upload className="h-10 w-10 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">Drop your CSV file here or click to browse</p>
                            <p className="text-xs text-muted-foreground">Required: One keyword per row with "Keyword" header</p>
                        </div>
                    </label>
                ) : (
                    <div className="space-y-2">
                        <div className="flex items-center justify-center gap-2">
                            <FileUp className="h-5 w-5 text-green-600" />
                            <span className="font-medium">{file.name}</span>
                            <span className="text-sm text-muted-foreground">({(file.size / 1024).toFixed(2)} KB)</span>
                        </div>
                        {isProcessing && (
                            <div className="space-y-2">
                                <Progress value={progress} className="h-2" />
                                <p className="text-xs text-muted-foreground">Processing... {progress}%</p>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Validation Errors */}
            {errors.length > 0 && (
                <Alert variant="destructive">
                    <Info className="h-4 w-4" />
                    <AlertDescription>
                        <div className="space-y-2">
                            <p className="font-semibold">
                                Found {errors.length} validation error
                                {errors.length > 1 ? 's' : ''}:
                            </p>
                            <ul className="max-h-40 space-y-1 overflow-y-auto text-sm">
                                {errors.slice(0, 10).map((error, index) => (
                                    <li key={index} className="font-mono text-xs">
                                        Row {error.row}, {error.field}: {error.message}
                                    </li>
                                ))}
                                {errors.length > 10 && <li className="text-muted-foreground italic">... and {errors.length - 10} more errors</li>}
                            </ul>
                        </div>
                    </AlertDescription>
                </Alert>
            )}

            {/* Success Preview */}
            {parsedData.length > 0 && errors.length === 0 && (
                <Alert className="border-green-500 bg-green-50">
                    <Info className="h-4 w-4 text-green-600" />
                    <AlertDescription>
                        <p className="text-sm text-green-800">
                            ✓ Successfully parsed {parsedData.length} keyword
                            {parsedData.length > 1 ? 's' : ''}
                            {duplicatesRemoved > 0 && ` (${duplicatesRemoved} duplicate${duplicatesRemoved > 1 ? 's' : ''} removed)`}
                            {alreadyExisting > 0 && `, ${alreadyExisting} already exist${alreadyExisting > 1 ? '' : 's'}`}. Ready to import!
                        </p>
                        {parsedData.length > 0 && (
                            <div className="mt-2 max-h-40 overflow-y-auto rounded border bg-white p-2">
                                <p className="mb-1 text-xs font-semibold">Preview (first 10):</p>
                                <ul className="space-y-1 text-xs">
                                    {parsedData.slice(0, 10).map((keyword, index) => (
                                        <li key={index} className="font-mono">
                                            {keyword}
                                        </li>
                                    ))}
                                    {parsedData.length > 10 && (
                                        <li className="text-muted-foreground italic">... and {parsedData.length - 10} more</li>
                                    )}
                                </ul>
                            </div>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {/* Action Buttons */}
            <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button onClick={handleImport} disabled={parsedData.length === 0 || errors.length > 0 || isProcessing}>
                    Import {parsedData.length > 0 && `${parsedData.length} Keyword${parsedData.length > 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
