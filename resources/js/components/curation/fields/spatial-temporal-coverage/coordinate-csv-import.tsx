/**
 * CoordinateCsvImport Component
 *
 * CSV bulk import for polygon/line coordinate pairs:
 * - Drag & drop or file selection
 * - Auto-detects lat/lon or lon/lat column order from headers
 * - Validation with detailed error reporting
 * - Preview of parsed coordinates with duplicate detection
 * - Replace or append mode when existing points are present
 * - Example CSV template download
 *
 * Based on free-keywords-csv-import.tsx design pattern
 */

import { Download, FileUp, Info, Upload, X } from 'lucide-react';
import Papa from 'papaparse';
import { useCallback, useRef, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

import type { PolygonPoint } from './types';

const MAX_POINTS_COUNT = 10_000;
const MAX_ERRORS_DISPLAYED = 10;
const PREVIEW_COUNT = 10;

interface CsvRow {
    [key: string]: string;
}

interface ValidationError {
    row: number;
    field: string;
    value: string;
    message: string;
}

interface CoordinateCsvImportProps {
    onImport: (points: PolygonPoint[], mode: 'replace' | 'append') => void;
    onClose: () => void;
    existingPointCount: number;
    geoType: 'polygon' | 'line';
}

const LAT_HEADERS = ['latitude', 'lat'];
const LON_HEADERS = ['longitude', 'lon', 'lng'];

/**
 * Detect column mapping from CSV headers.
 * Returns { latKey, lonKey } or null if headers are unrecognizable.
 */
function detectColumns(headers: string[]): { latKey: string; lonKey: string } | null {
    const normalized = headers.map((h) => h.trim().toLowerCase());

    let latKey: string | undefined;
    let lonKey: string | undefined;

    for (const header of headers) {
        const lower = header.trim().toLowerCase();
        if (!latKey && LAT_HEADERS.includes(lower)) {
            latKey = header;
        } else if (!lonKey && LON_HEADERS.includes(lower)) {
            lonKey = header;
        }
    }

    if (latKey && lonKey) {
        return { latKey, lonKey };
    }

    // Fallback: exactly 2 columns with no recognized headers → assume lat, lon order
    if (normalized.length === 2 && !latKey && !lonKey) {
        return { latKey: headers[0], lonKey: headers[1] };
    }

    return null;
}

/**
 * Validate if a file is a CSV file based on MIME type and/or file extension
 */
function isValidCsvFile(file: File): boolean {
    const validMimeTypes = [
        'text/csv',
        'application/csv',
        'text/x-csv',
        'application/x-csv',
        'text/comma-separated-values',
        'text/x-comma-separated-values',
        'application/vnd.ms-excel',
    ];

    if (file.type && validMimeTypes.includes(file.type)) {
        return true;
    }

    return file.name.toLowerCase().endsWith('.csv');
}

/**
 * Check if two consecutive points are identical
 */
function isConsecutiveDuplicate(a: PolygonPoint, b: PolygonPoint): boolean {
    return a.lat === b.lat && a.lon === b.lon;
}

export default function CoordinateCsvImport({ onImport, onClose, existingPointCount, geoType }: CoordinateCsvImportProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const parseGenerationRef = useRef(0);
    const [file, setFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [progress, setProgress] = useState(0);
    const [errors, setErrors] = useState<ValidationError[]>([]);
    const [parsedData, setParsedData] = useState<PolygonPoint[]>([]);
    const [importMode, setImportMode] = useState<'replace' | 'append'>('replace');
    const [fileValidationError, setFileValidationError] = useState<string | null>(null);
    const [duplicatesRemoved, setDuplicatesRemoved] = useState(0);
    const [headerFallback, setHeaderFallback] = useState(false);

    const minPoints = geoType === 'polygon' ? 3 : 2;
    const geoLabel = geoType === 'polygon' ? 'polygon' : 'line';

    const parseCSV = useCallback(
        async (csvFile: File) => {
            const generation = ++parseGenerationRef.current;
            setIsProcessing(true);
            setErrors([]);
            setParsedData([]);
            setDuplicatesRemoved(0);
            setHeaderFallback(false);
            setProgress(0);

            try {
                const text = await csvFile.text();

                Papa.parse<CsvRow>(text, {
                    header: true,
                    skipEmptyLines: true,
                    preview: MAX_POINTS_COUNT + 1,
                    complete: (results) => {
                        if (generation !== parseGenerationRef.current) return;
                        if (results.data.length === 0) {
                            setErrors([{ row: 0, field: 'file', value: '', message: 'CSV file is empty or has no data rows' }]);
                            setIsProcessing(false);
                            return;
                        }

                        if (results.data.length > MAX_POINTS_COUNT) {
                            setErrors([
                                {
                                    row: 0,
                                    field: 'file',
                                    value: '',
                                    message: `Too many coordinate pairs. Maximum is ${MAX_POINTS_COUNT.toLocaleString()}, file contains more than ${MAX_POINTS_COUNT.toLocaleString()} rows`,
                                },
                            ]);
                            setIsProcessing(false);
                            return;
                        }

                        // Detect column mapping
                        const headers = results.meta.fields || [];
                        const columns = detectColumns(headers);

                        if (!columns) {
                            setErrors([
                                {
                                    row: 0,
                                    field: 'headers',
                                    value: headers.join(', '),
                                    message:
                                        'Could not detect latitude/longitude columns. Use headers like "latitude,longitude" or "lat,lon"',
                                },
                            ]);
                            setIsProcessing(false);
                            return;
                        }

                        // Check if fallback was used (no recognized headers)
                        const latLower = columns.latKey.trim().toLowerCase();
                        const lonLower = columns.lonKey.trim().toLowerCase();
                        if (!LAT_HEADERS.includes(latLower) && !LON_HEADERS.includes(lonLower)) {
                            setHeaderFallback(true);
                        }

                        const validationErrors: ValidationError[] = [];
                        const rawPoints: PolygonPoint[] = [];
                        let lastPercent = 0;

                        results.data.forEach((row, index) => {
                            const rowNum = index + 2; // +1 header, +1 for 1-based

                            // Update progress based on row index regardless of validity
                            const percent = Math.round(((index + 1) / results.data.length) * 100);
                            if (percent !== lastPercent) {
                                lastPercent = percent;
                                setProgress(percent);
                            }

                            const latStr = row[columns.latKey]?.trim();
                            const lonStr = row[columns.lonKey]?.trim();

                            // Skip only rows where ALL fields are empty/whitespace
                            const allFieldsEmpty = Object.values(row).every((v) => !v?.trim());
                            if (allFieldsEmpty) return;

                            // Validate latitude
                            if (!latStr) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'latitude',
                                    value: '',
                                    message: 'Latitude is empty',
                                });
                                return;
                            }

                            const lat = parseFloat(latStr);
                            if (isNaN(lat)) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'latitude',
                                    value: latStr,
                                    message: `"${latStr}" is not a valid number`,
                                });
                                return;
                            }

                            if (lat < -90 || lat > 90) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'latitude',
                                    value: latStr,
                                    message: `${lat} is out of range (must be -90 to +90)`,
                                });
                                return;
                            }

                            // Validate longitude
                            if (!lonStr) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'longitude',
                                    value: '',
                                    message: 'Longitude is empty',
                                });
                                return;
                            }

                            const lon = parseFloat(lonStr);
                            if (isNaN(lon)) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'longitude',
                                    value: lonStr,
                                    message: `"${lonStr}" is not a valid number`,
                                });
                                return;
                            }

                            if (lon < -180 || lon > 180) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'longitude',
                                    value: lonStr,
                                    message: `${lon} is out of range (must be -180 to +180)`,
                                });
                                return;
                            }

                            rawPoints.push({
                                lat: Number(lat.toFixed(6)),
                                lon: Number(lon.toFixed(6)),
                            });
                        });

                        // Remove consecutive duplicates
                        let duplicatesCount = 0;
                        const dedupedPoints: PolygonPoint[] = [];
                        for (let i = 0; i < rawPoints.length; i++) {
                            if (i > 0 && isConsecutiveDuplicate(rawPoints[i], rawPoints[i - 1])) {
                                duplicatesCount++;
                            } else {
                                dedupedPoints.push(rawPoints[i]);
                            }
                        }

                        setErrors(validationErrors);
                        setParsedData(dedupedPoints);
                        setDuplicatesRemoved(duplicatesCount);
                        setProgress(100);
                        setIsProcessing(false);
                    },
                    error: (error: Error) => {
                        if (generation !== parseGenerationRef.current) return;
                        setErrors([{ row: 0, field: 'file', value: '', message: error.message || 'Failed to parse CSV file' }]);
                        setIsProcessing(false);
                    },
                });
            } catch (error) {
                if (generation !== parseGenerationRef.current) return;
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
        [],
    );

    const resetParsedState = () => {
        parseGenerationRef.current++;
        setParsedData([]);
        setErrors([]);
        setProgress(0);
        setIsProcessing(false);
        setDuplicatesRemoved(0);
        setHeaderFallback(false);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const selectedFile = event.target.files?.[0];
        if (selectedFile) {
            if (isValidCsvFile(selectedFile)) {
                setFileValidationError(null);
                setFile(selectedFile);
                parseCSV(selectedFile);
            } else {
                setFileValidationError('Please select a valid CSV file');
                setFile(null);
                resetParsedState();
            }
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
        if (droppedFile) {
            if (isValidCsvFile(droppedFile)) {
                setFileValidationError(null);
                setFile(droppedFile);
                parseCSV(droppedFile);
            } else {
                setFileValidationError('Please drop a valid CSV file');
                setFile(null);
                resetParsedState();
            }
        }
    };

    const handleImport = () => {
        if (parsedData.length >= minPoints) {
            onImport(parsedData, importMode);
            onClose();
        }
    };

    const downloadExample = () => {
        const exampleCSV =
            geoType === 'polygon'
                ? `latitude,longitude
52.3810,13.0660
52.3820,13.0680
52.3815,13.0700
52.3800,13.0690
52.3805,13.0650`
                : `latitude,longitude
52.3810,13.0660
52.3815,13.0670
52.3820,13.0680`;

        const blob = new Blob([exampleCSV], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${geoLabel}-coordinates-example.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const hasValidData = parsedData.length > 0;

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="space-y-1">
                    <Label className="text-base font-semibold">CSV Coordinate Import</Label>
                    <p className="text-sm text-muted-foreground">
                        Import coordinate pairs for your {geoLabel} from a CSV file
                    </p>
                </div>
                <Button type="button" variant="ghost" size="icon" onClick={onClose} aria-label="Close CSV import">
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {/* File Validation Error */}
            {fileValidationError && (
                <Alert variant="destructive">
                    <AlertDescription>{fileValidationError}</AlertDescription>
                </Alert>
            )}

            {/* Example Download */}
            <Alert>
                <Info className="h-4 w-4" />
                <AlertDescription className="flex items-center justify-between">
                    <span className="text-sm">
                        Need a template? Supports <code className="rounded bg-muted px-1 text-xs">latitude,longitude</code> or{' '}
                        <code className="rounded bg-muted px-1 text-xs">lon,lat</code> column headers
                    </span>
                    <Button type="button" variant="outline" size="sm" onClick={downloadExample}>
                        <Download className="mr-2 h-3 w-3" />
                        Example CSV
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
                <input
                    ref={fileInputRef}
                    type="file"
                    id="csv-upload-coordinates"
                    accept=".csv,text/csv"
                    onChange={handleFileSelect}
                    className="sr-only"
                />

                {!file ? (
                    <label htmlFor="csv-upload-coordinates" className="flex cursor-pointer flex-col items-center gap-2">
                        <Upload className="h-10 w-10 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">Drop your CSV file here or click to browse</p>
                            <p className="text-xs text-muted-foreground">
                                One coordinate pair per row with latitude and longitude columns
                            </p>
                        </div>
                    </label>
                ) : (
                    <div className="space-y-2">
                        <div className="flex items-center justify-center gap-2">
                            <FileUp className="h-5 w-5 text-green-600" />
                            <span className="font-medium">{file.name}</span>
                            <span className="text-sm text-muted-foreground">({(file.size / 1024).toFixed(2)} KB)</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-6 w-6"
                                onClick={() => {
                                    setFile(null);
                                    setFileValidationError(null);
                                    resetParsedState();
                                }}
                                aria-label="Clear selected file"
                            >
                                <X className="h-3 w-3" />
                            </Button>
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

            {/* Header Fallback Warning */}
            {headerFallback && parsedData.length > 0 && (
                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertDescription className="text-sm">
                        No recognized column headers found. Assuming column order: <strong>latitude</strong>, <strong>longitude</strong>.
                        For explicit mapping, use headers like &quot;latitude,longitude&quot; or &quot;lat,lon&quot;.
                    </AlertDescription>
                </Alert>
            )}

            {/* Validation Errors */}
            {errors.length > 0 && (
                <Alert variant="destructive">
                    <Info className="h-4 w-4" />
                    <AlertDescription>
                        <div className="space-y-2">
                            <p className="font-semibold">
                                Found {errors.length} validation error{errors.length > 1 ? 's' : ''}:
                            </p>
                            <ul className="max-h-40 space-y-1 overflow-y-auto text-sm">
                                {errors.slice(0, MAX_ERRORS_DISPLAYED).map((error, index) => (
                                    <li key={index} className="font-mono text-xs">
                                        Row {error.row}, {error.field}: {error.message}
                                    </li>
                                ))}
                                {errors.length > MAX_ERRORS_DISPLAYED && (
                                    <li className="text-muted-foreground italic">... and {errors.length - MAX_ERRORS_DISPLAYED} more errors</li>
                                )}
                            </ul>
                        </div>
                    </AlertDescription>
                </Alert>
            )}

            {/* Success Preview */}
            {hasValidData && (
                <Alert className="border-green-500 bg-green-50 dark:bg-green-950/20">
                    <Info className="h-4 w-4 text-green-600" />
                    <AlertDescription>
                        <p className="text-sm text-green-800 dark:text-green-200">
                            ✓ Successfully parsed {parsedData.length.toLocaleString()} coordinate pair
                            {parsedData.length > 1 ? 's' : ''}
                            {duplicatesRemoved > 0 &&
                                ` (${duplicatesRemoved} consecutive duplicate${duplicatesRemoved > 1 ? 's' : ''} removed)`}
                            . Ready to import!
                        </p>
                        {parsedData.length < minPoints && (
                            <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">
                                ⚠️ A valid {geoLabel} requires at least {minPoints} points. Only {parsedData.length} parsed.
                            </p>
                        )}
                        <div className="mt-2 max-h-48 overflow-y-auto rounded border bg-white p-2 dark:bg-transparent">
                            <p className="mb-1 text-xs font-semibold">Preview (first {Math.min(PREVIEW_COUNT, parsedData.length)}):</p>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="h-8 w-10 px-2 text-xs">#</TableHead>
                                        <TableHead className="h-8 px-2 text-xs">Latitude</TableHead>
                                        <TableHead className="h-8 px-2 text-xs">Longitude</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {parsedData.slice(0, PREVIEW_COUNT).map((point, index) => (
                                        <TableRow key={index}>
                                            <TableCell className="px-2 py-1 font-mono text-xs">{index + 1}</TableCell>
                                            <TableCell className="px-2 py-1 font-mono text-xs">{point.lat}</TableCell>
                                            <TableCell className="px-2 py-1 font-mono text-xs">{point.lon}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            {parsedData.length > PREVIEW_COUNT && (
                                <p className="mt-1 text-xs text-muted-foreground italic">
                                    ... and {(parsedData.length - PREVIEW_COUNT).toLocaleString()} more
                                </p>
                            )}
                        </div>
                    </AlertDescription>
                </Alert>
            )}

            {/* Replace / Append Mode */}
            {existingPointCount > 0 && hasValidData && (
                <div className="space-y-3 rounded-lg border p-4">
                    <Label className="text-sm font-medium">Import Mode</Label>
                    <RadioGroup value={importMode} onValueChange={(value) => setImportMode(value as 'replace' | 'append')}>
                        <div className="flex items-center space-x-2">
                            <RadioGroupItem value="replace" id="mode-replace" />
                            <Label htmlFor="mode-replace" className="cursor-pointer text-sm font-normal">
                                Replace existing {existingPointCount} point{existingPointCount > 1 ? 's' : ''} with imported data
                            </Label>
                        </div>
                        <div className="flex items-center space-x-2">
                            <RadioGroupItem value="append" id="mode-append" />
                            <Label htmlFor="mode-append" className="cursor-pointer text-sm font-normal">
                                Append imported data to existing {existingPointCount} point{existingPointCount > 1 ? 's' : ''}
                            </Label>
                        </div>
                    </RadioGroup>
                </div>
            )}

            {/* Action Buttons */}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="button" onClick={handleImport} disabled={parsedData.length < minPoints || isProcessing}>
                    {parsedData.length > 0
                        ? `Import ${parsedData.length.toLocaleString()} Point${parsedData.length > 1 ? 's' : ''}`
                        : 'Import'}
                </Button>
            </div>
        </div>
    );
}
