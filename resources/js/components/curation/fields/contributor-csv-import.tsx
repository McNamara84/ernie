/**
 * ContributorCsvImport Component
 * 
 * CSV bulk import for contributors:
 * - Drag & drop or file selection
 * - Validation with detailed error reporting
 * - Preview of parsed data
 * - Example CSV template download
 * 
 * Based on author-csv-import.tsx
 */

import { FileUp, Info, Upload, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import Papa from 'papaparse';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';

interface CsvRow {
    [key: string]: string;
}

export interface ParsedContributor {
    type: 'person' | 'institution';
    firstName?: string;
    lastName?: string;
    orcid?: string;
    email?: string;
    institutionName?: string;
    affiliations: string[];
    contributorRole?: string;
}

interface ValidationError {
    row: number;
    field: string;
    value: string;
    message: string;
}

interface ContributorCsvImportProps {
    onImport: (data: ParsedContributor[]) => void;
    onClose: () => void;
}

/**
 * Validate ORCID format
 */
function isValidOrcid(orcid: string): boolean {
    if (!orcid) return true; // Empty is OK
    return /^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/.test(orcid);
}

/**
 * ContributorCsvImport Component
 */
export default function ContributorCsvImport({ onImport, onClose }: ContributorCsvImportProps) {
    const [file, setFile] = useState<File | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [progress, setProgress] = useState(0);
    const [errors, setErrors] = useState<ValidationError[]>([]);
    const [parsedData, setParsedData] = useState<ParsedContributor[]>([]);

    const parseCSV = useCallback(async (csvFile: File) => {
        setIsProcessing(true);
        setErrors([]);
        setParsedData([]);
        setProgress(0);

        try {
            const text = await csvFile.text();
            
            // Parse CSV with PapaParse
            Papa.parse<CsvRow>(text, {
                header: true,
                skipEmptyLines: true,
                complete: (results) => {
                    if (results.data.length === 0) {
                        setErrors([{
                            row: 0,
                            field: 'file',
                            value: '',
                            message: 'CSV file is empty or has no data rows',
                        }]);
                        setIsProcessing(false);
                        return;
                    }

                    const validationErrors: ValidationError[] = [];
                    const data: ParsedContributor[] = [];

                    results.data.forEach((row, index) => {
                        const rowNum = index + 2; // +1 for header, +1 for 1-based
                        
                        // Determine type
                        const typeField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('type') || k.toLowerCase().includes('typ')
                        );
                        const typeValue = typeField ? row[typeField]?.trim().toLowerCase() : 'person';
                        const type: 'person' | 'institution' = 
                            typeValue === 'organization' || typeValue === 'institution' 
                                ? 'institution' 
                                : 'person';

                        // Extract fields
                        const firstNameField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('first') || 
                            k.toLowerCase().includes('vorname') || 
                            k.toLowerCase().includes('given')
                        );
                        const lastNameField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('last') || 
                            k.toLowerCase().includes('nachname') || 
                            k.toLowerCase().includes('family')
                        );
                        const orcidField = Object.keys(row).find(k => k.toLowerCase().includes('orcid'));
                        const emailField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('email') || k.toLowerCase().includes('mail')
                        );
                        const orgNameField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('organization') || 
                            k.toLowerCase().includes('organisation') || 
                            k.toLowerCase().includes('institution')
                        );
                        const affiliationsField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('affiliation')
                        );
                        const roleField = Object.keys(row).find(k => 
                            k.toLowerCase().includes('role') || 
                            k.toLowerCase().includes('contributor')
                        );

                        const firstName = firstNameField ? row[firstNameField]?.trim() : '';
                        const lastName = lastNameField ? row[lastNameField]?.trim() : '';
                        const orcid = orcidField ? row[orcidField]?.trim() : '';
                        const email = emailField ? row[emailField]?.trim() : '';
                        const orgName = orgNameField ? row[orgNameField]?.trim() : '';
                        const affiliationsRaw = affiliationsField ? row[affiliationsField]?.trim() : '';
                        const contributorRole = roleField ? row[roleField]?.trim() : '';

                        const affiliations = affiliationsRaw
                            ? affiliationsRaw.split(',').map(a => a.trim()).filter(Boolean)
                            : [];

                        // Validation
                        if (type === 'person') {
                            if (!firstName && !lastName) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'name',
                                    value: '',
                                    message: 'First name or last name required for person type',
                                });
                            }
                        } else if (type === 'institution') {
                            if (!orgName) {
                                validationErrors.push({
                                    row: rowNum,
                                    field: 'organization name',
                                    value: '',
                                    message: 'Organization name required for institution type',
                                });
                            }
                        }

                        // ORCID validation
                        if (orcid && !isValidOrcid(orcid)) {
                            validationErrors.push({
                                row: rowNum,
                                field: 'orcid',
                                value: orcid,
                                message: 'Invalid ORCID format (expected: 0000-0000-0000-0000)',
                            });
                        }

                        // If valid, add to data
                        if (!validationErrors.some(e => e.row === rowNum)) {
                            if (type === 'person') {
                                data.push({
                                    type: 'person',
                                    firstName,
                                    lastName,
                                    orcid,
                                    email,
                                    affiliations,
                                    contributorRole,
                                });
                            } else {
                                data.push({
                                    type: 'institution',
                                    institutionName: orgName,
                                    affiliations,
                                    contributorRole,
                                });
                            }
                        }

                        setProgress(Math.round(((index + 1) / results.data.length) * 100));
                    });

                    setErrors(validationErrors);
                    setParsedData(data);
                    setProgress(100);
                    setIsProcessing(false);
                },
                error: (error: any) => {
                    setErrors([{
                        row: 0,
                        field: 'file',
                        value: '',
                        message: error.message || 'Failed to parse CSV file',
                    }]);
                    setIsProcessing(false);
                },
            });
        } catch (error) {
            setErrors([{
                row: 0,
                field: 'file',
                value: '',
                message: error instanceof Error ? error.message : 'Failed to parse CSV file',
            }]);
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
        const exampleCSV = `Type,First Name,Last Name,ORCID,Email,Organization Name,Affiliations,Contributor Role
person,Anna,Schmidt,0000-0003-1111-2222,anna.schmidt@example.de,,Technical University Munich,DataCollector
person,Peter,Meyer,,,,University of Heidelberg,Editor
organization,,,,,Max Planck Society,,Sponsor
person,Sarah,Johnson,0000-0002-3333-4444,sarah.j@example.com,,ETH Zurich,DataCurator`;

        const blob = new Blob([exampleCSV], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'contributors-example.csv';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="space-y-1">
                    <Label className="text-base font-semibold">CSV Bulk Import</Label>
                    <p className="text-sm text-muted-foreground">
                        Import multiple contributors from a CSV file
                    </p>
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
                    id="csv-upload-contributors"
                    accept=".csv,text/csv"
                    onChange={handleFileSelect}
                    className="sr-only"
                />

                {!file ? (
                    <label htmlFor="csv-upload-contributors" className="flex cursor-pointer flex-col items-center gap-2">
                        <Upload className="h-10 w-10 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                Drop your CSV file here or click to browse
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Required: Type, First Name/Last Name (for persons) or Organization Name (for institutions)
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
                            ✓ Successfully parsed {parsedData.length} contributor{parsedData.length > 1 ? 's' : ''}. Ready
                            to import!
                        </p>
                        {parsedData.length > 0 && (
                            <div className="mt-2 max-h-40 overflow-y-auto rounded border bg-white p-2">
                                <p className="mb-1 text-xs font-semibold">Preview (first 5):</p>
                                <ul className="space-y-1 text-xs">
                                    {parsedData.slice(0, 5).map((item, index) => (
                                        <li key={index} className="font-mono">
                                            {item.type === 'person'
                                                ? `${item.firstName} ${item.lastName}${item.orcid ? ` (${item.orcid})` : ''}`
                                                : item.institutionName}
                                            {item.contributorRole && ` - ${item.contributorRole}`}
                                            {item.affiliations.length > 0 && ` - ${item.affiliations.join(', ')}`}
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
                <Button variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    onClick={handleImport}
                    disabled={parsedData.length === 0 || errors.length > 0 || isProcessing}
                >
                    Import {parsedData.length > 0 && `${parsedData.length} Contributor${parsedData.length > 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
