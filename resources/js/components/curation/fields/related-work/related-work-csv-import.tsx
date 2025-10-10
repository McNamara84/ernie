import { FileUp, Info, Upload, X } from 'lucide-react';
import { useCallback, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import type { IdentifierType, RelatedIdentifierFormData, RelationType } from '@/types';

interface RelatedWorkCsvImportProps {
    onImport: (data: RelatedIdentifierFormData[]) => void;
    onClose: () => void;
}

interface CsvRow {
    identifier: string;
    identifier_type: string;
    relation_type: string;
}

interface ValidationError {
    row: number;
    field: string;
    value: string;
    message: string;
}

/**
 * RelatedWorkCsvImport Component
 * 
 * CSV bulk import for related works:
 * - Drag & drop or file selection
 * - Validation with detailed error reporting
 * - Preview of parsed data
 * - Example CSV template download
 */
export default function RelatedWorkCsvImport({
    onImport,
    onClose,
}: RelatedWorkCsvImportProps) {
    const [file, setFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [progress, setProgress] = useState(0);
    const [errors, setErrors] = useState<ValidationError[]>([]);
    const [parsedData, setParsedData] = useState<RelatedIdentifierFormData[]>([]);

    const parseCSV = useCallback(async (csvFile: File) => {
        const validIdentifierTypes = [
            'DOI', 'URL', 'Handle', 'IGSN', 'URN', 'ISBN', 'ISSN',
            'PURL', 'ARK', 'arXiv', 'bibcode', 'EAN13', 'EISSN',
            'ISTC', 'LISSN', 'LSID', 'PMID', 'UPC', 'w3id',
        ];

        const validRelationTypes = [
            'Cites', 'IsCitedBy', 'References', 'IsReferencedBy',
            'IsSupplementTo', 'IsSupplementedBy', 'IsContinuedBy',
            'Continues', 'Describes', 'IsDescribedBy', 'HasMetadata',
            'IsMetadataFor', 'HasVersion', 'IsVersionOf', 'IsNewVersionOf',
            'IsPreviousVersionOf', 'IsPartOf', 'HasPart', 'IsPublishedIn',
            'IsReferencedBy', 'References', 'IsDocumentedBy', 'Documents',
            'IsCompiledBy', 'Compiles', 'IsVariantFormOf', 'IsOriginalFormOf',
            'IsIdenticalTo', 'IsReviewedBy', 'Reviews', 'IsDerivedFrom',
            'IsSourceOf', 'IsRequiredBy', 'Requires',
        ];
        
        setIsProcessing(true);
        setErrors([]);
        setParsedData([]);
        setProgress(0);

        try {
            const text = await csvFile.text();
            const lines = text.split('\n').filter(line => line.trim());

            if (lines.length < 2) {
                setErrors([{
                    row: 0,
                    field: 'file',
                    value: '',
                    message: 'CSV file is empty or has no data rows',
                }]);
                setIsProcessing(false);
                return;
            }

            // Parse header
            const header = lines[0].split(',').map(h => h.trim().toLowerCase());
            const requiredColumns = ['identifier', 'identifier_type', 'relation_type'];
            const missingColumns = requiredColumns.filter(col => !header.includes(col));

            if (missingColumns.length > 0) {
                setErrors([{
                    row: 0,
                    field: 'header',
                    value: header.join(', '),
                    message: `Missing required columns: ${missingColumns.join(', ')}`,
                }]);
                setIsProcessing(false);
                return;
            }

            // Parse data rows
            const validationErrors: ValidationError[] = [];
            const data: RelatedIdentifierFormData[] = [];

            for (let i = 1; i < lines.length; i++) {
                const line = lines[i];
                const values = line.split(',').map(v => v.trim());
                
                if (values.length < 3) {
                    validationErrors.push({
                        row: i + 1,
                        field: 'row',
                        value: line,
                        message: 'Row has insufficient columns',
                    });
                    continue;
                }

                const row: CsvRow = {
                    identifier: values[header.indexOf('identifier')],
                    identifier_type: values[header.indexOf('identifier_type')],
                    relation_type: values[header.indexOf('relation_type')],
                };

                // Validate identifier
                if (!row.identifier || row.identifier.length === 0) {
                    validationErrors.push({
                        row: i + 1,
                        field: 'identifier',
                        value: row.identifier,
                        message: 'Identifier is required',
                    });
                }

                // Validate identifier_type
                if (!validIdentifierTypes.includes(row.identifier_type)) {
                    validationErrors.push({
                        row: i + 1,
                        field: 'identifier_type',
                        value: row.identifier_type,
                        message: `Invalid identifier type. Must be one of: ${validIdentifierTypes.join(', ')}`,
                    });
                }

                // Validate relation_type
                if (!validRelationTypes.includes(row.relation_type)) {
                    validationErrors.push({
                        row: i + 1,
                        field: 'relation_type',
                        value: row.relation_type,
                        message: `Invalid relation type. Must be one of DataCite Schema 4.6 types`,
                    });
                }

                // If valid, add to data
                if (validationErrors.length === 0 || validationErrors.every(e => e.row !== i + 1)) {
                    data.push({
                        identifier: row.identifier,
                        identifierType: row.identifier_type as IdentifierType,
                        relationType: row.relation_type as RelationType,
                    });
                }

                setProgress(Math.round((i / lines.length) * 100));
            }

            setErrors(validationErrors);
            setParsedData(data);
            setProgress(100);
        } catch (error) {
            setErrors([{
                row: 0,
                field: 'file',
                value: '',
                message: error instanceof Error ? error.message : 'Failed to parse CSV file',
            }]);
        } finally {
            setIsProcessing(false);
        }
    }, []);

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
        const exampleCSV = `identifier,identifier_type,relation_type
10.5194/nhess-15-1463-2015,DOI,Cites
10.1007/s11069-014-1480-x,DOI,References
https://example.org/dataset/123,URL,IsSupplementTo
10.5281/zenodo.1234567,DOI,IsDerivedFrom`;

        const blob = new Blob([exampleCSV], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'related-works-example.csv';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="space-y-1">
                    <Label className="text-base font-semibold">
                        CSV Bulk Import
                    </Label>
                    <p className="text-sm text-muted-foreground">
                        Import multiple related works from a CSV file
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onClose}
                    aria-label="Close CSV import"
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {/* Example Download */}
            <Alert>
                <Info className="h-4 w-4" />
                <AlertDescription className="flex items-center justify-between">
                    <span className="text-sm">
                        Need a template? Download our example CSV file
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={downloadExample}
                    >
                        <FileUp className="mr-2 h-3 w-3" />
                        Download Example
                    </Button>
                </AlertDescription>
            </Alert>

            {/* File Upload Area */}
            <div
                className={`
                    relative rounded-lg border-2 border-dashed p-8 text-center transition-colors
                    ${isDragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'}
                    ${file ? 'bg-muted/50' : ''}
                `}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
            >
                <input
                    type="file"
                    id="csv-upload"
                    accept=".csv,text/csv"
                    onChange={handleFileSelect}
                    className="sr-only"
                />
                
                {!file ? (
                    <label
                        htmlFor="csv-upload"
                        className="flex cursor-pointer flex-col items-center gap-2"
                    >
                        <Upload className="h-10 w-10 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                Drop your CSV file here or click to browse
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Accepts .csv files with columns: identifier, identifier_type, relation_type
                            </p>
                        </div>
                    </label>
                ) : (
                    <div className="space-y-2">
                        <div className="flex items-center justify-center gap-2">
                            <FileUp className="h-5 w-5 text-green-600" />
                            <span className="font-medium">{file.name}</span>
                            <span className="text-sm text-muted-foreground">
                                ({(file.size / 1024).toFixed(2)} KB)
                            </span>
                        </div>
                        {isProcessing && (
                            <div className="space-y-2">
                                <Progress value={progress} className="h-2" />
                                <p className="text-xs text-muted-foreground">
                                    Processing... {progress}%
                                </p>
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
                                Found {errors.length} validation error{errors.length > 1 ? 's' : ''}:
                            </p>
                            <ul className="max-h-40 space-y-1 overflow-y-auto text-sm">
                                {errors.slice(0, 10).map((error, index) => (
                                    <li key={index} className="font-mono text-xs">
                                        Row {error.row}, {error.field}: {error.message}
                                    </li>
                                ))}
                                {errors.length > 10 && (
                                    <li className="italic text-muted-foreground">
                                        ... and {errors.length - 10} more errors
                                    </li>
                                )}
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
                            ✓ Successfully parsed {parsedData.length} related work{parsedData.length > 1 ? 's' : ''}.
                            Ready to import!
                        </p>
                        {parsedData.length > 0 && (
                            <div className="mt-2 max-h-40 overflow-y-auto rounded border bg-white p-2">
                                <p className="mb-1 text-xs font-semibold">Preview (first 5):</p>
                                <ul className="space-y-1 text-xs">
                                    {parsedData.slice(0, 5).map((item, index) => (
                                        <li key={index} className="font-mono">
                                            {item.relationType}: {item.identifier} ({item.identifierType})
                                        </li>
                                    ))}
                                    {parsedData.length > 5 && (
                                        <li className="italic text-muted-foreground">
                                            ... and {parsedData.length - 5} more
                                        </li>
                                    )}
                                </ul>
                            </div>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {/* Action Buttons */}
            <div className="flex justify-end gap-2">
                <Button
                    variant="outline"
                    onClick={onClose}
                >
                    Cancel
                </Button>
                <Button
                    onClick={handleImport}
                    disabled={parsedData.length === 0 || errors.length > 0 || isProcessing}
                >
                    Import {parsedData.length > 0 && `${parsedData.length} Items`}
                </Button>
            </div>
        </div>
    );
}
