/**
 * CsvImportDialog Component
 * 
 * Modal dialog for importing Authors or Contributors from CSV files.
 * Features: File upload, validation, preview, and bulk import.
 * Based on related-work-csv-import.tsx design.
 */

import { FileUp, Info, Upload, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import Papa from 'papaparse';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';

interface CsvRow {
    [key: string]: string;
}

interface MappedRow {
    firstName?: string;
    lastName?: string;
    orcid?: string;
    email?: string;
    affiliations?: string[];
    isContact?: boolean;
    type?: 'person' | 'organization';
    organizationName?: string;
    errors?: string[];
}

interface CsvImportDialogProps {
    onImport: (rows: MappedRow[]) => void;
    type: 'author' | 'contributor';
    triggerClassName?: string;
}

const FIELD_OPTIONS = [
    { value: 'firstName', label: 'Vorname (First Name)' },
    { value: 'lastName', label: 'Nachname (Last Name)' },
    { value: 'orcid', label: 'ORCID' },
    { value: 'email', label: 'Email' },
    { value: 'affiliations', label: 'Affiliations (comma-separated)' },
    { value: 'isContact', label: 'Contact Person (yes/no)' },
    { value: 'type', label: 'Type (person/organization)' },
    { value: 'organizationName', label: 'Organization Name' },
    { value: 'ignore', label: '--- Ignorieren ---' },
];

export function CsvImportDialog({ onImport, type, triggerClassName }: CsvImportDialogProps) {
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<'upload' | 'mapping' | 'preview'>('upload');
    const [csvData, setCsvData] = useState<CsvRow[]>([]);
    const [csvHeaders, setCsvHeaders] = useState<string[]>([]);
    const [columnMapping, setColumnMapping] = useState<Record<string, string>>({});
    const [mappedData, setMappedData] = useState<MappedRow[]>([]);
    const [fileName, setFileName] = useState('');

    // Generate and download example CSV
    const handleDownloadExample = () => {
        const exampleData = type === 'author' 
            ? [
                {
                    'Type': 'person',
                    'First Name': 'Max',
                    'Last Name': 'Mustermann',
                    'ORCID': '0000-0002-1234-5678',
                    'Email': 'max.mustermann@example.com',
                    'Affiliations': 'Helmholtz Centre Potsdam - GFZ, University of Potsdam',
                    'Contact Person': 'yes',
                },
                {
                    'Type': 'person',
                    'First Name': 'Erika',
                    'Last Name': 'Musterfrau',
                    'ORCID': '',
                    'Email': 'erika.musterfrau@example.org',
                    'Affiliations': 'Freie Universität Berlin',
                    'Contact Person': 'no',
                },
                {
                    'Type': 'organization',
                    'First Name': '',
                    'Last Name': '',
                    'ORCID': '',
                    'Email': '',
                    'Organization Name': 'Deutsche Forschungsgemeinschaft (DFG)',
                    'Affiliations': '',
                    'Contact Person': '',
                },
                {
                    'Type': 'person',
                    'First Name': 'John',
                    'Last Name': 'Doe',
                    'ORCID': '0000-0001-9876-5432',
                    'Email': '',
                    'Affiliations': 'MIT, Harvard University',
                    'Contact Person': 'no',
                },
            ]
            : [
                {
                    'Type': 'person',
                    'First Name': 'Anna',
                    'Last Name': 'Schmidt',
                    'ORCID': '0000-0003-1111-2222',
                    'Email': 'anna.schmidt@example.de',
                    'Affiliations': 'Technical University Munich',
                    'Contributor Role': 'DataCollector',
                },
                {
                    'Type': 'person',
                    'First Name': 'Peter',
                    'Last Name': 'Meyer',
                    'ORCID': '',
                    'Email': '',
                    'Affiliations': 'University of Heidelberg',
                    'Contributor Role': 'Editor',
                },
                {
                    'Type': 'organization',
                    'First Name': '',
                    'Last Name': '',
                    'ORCID': '',
                    'Email': '',
                    'Organization Name': 'Max Planck Society',
                    'Affiliations': '',
                    'Contributor Role': 'Sponsor',
                },
                {
                    'Type': 'person',
                    'First Name': 'Sarah',
                    'Last Name': 'Johnson',
                    'ORCID': '0000-0002-3333-4444',
                    'Email': 'sarah.j@example.com',
                    'Affiliations': 'ETH Zurich',
                    'Contributor Role': 'DataCurator',
                },
            ];

        // Convert to CSV using PapaParse
        const csv = Papa.unparse(exampleData as any[]);
        
        // Create download
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', `example-${type}s-import.csv`);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const handleFileUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setFileName(file.name);

        Papa.parse<CsvRow>(file, {
            header: true,
            skipEmptyLines: true,
            complete: (results) => {
                if (results.data.length === 0) {
                    alert('CSV-Datei ist leer oder konnte nicht gelesen werden.');
                    return;
                }

                const headers = Object.keys(results.data[0]);
                setCsvHeaders(headers);
                setCsvData(results.data);

                // Auto-detect column mappings
                const autoMapping: Record<string, string> = {};
                headers.forEach((header) => {
                    const lowerHeader = header.toLowerCase().trim();
                    
                    if (lowerHeader.includes('first') || lowerHeader.includes('vorname') || lowerHeader === 'given name') {
                        autoMapping[header] = 'firstName';
                    } else if (lowerHeader.includes('last') || lowerHeader.includes('nachname') || lowerHeader.includes('family')) {
                        autoMapping[header] = 'lastName';
                    } else if (lowerHeader.includes('orcid')) {
                        autoMapping[header] = 'orcid';
                    } else if (lowerHeader.includes('email') || lowerHeader.includes('mail')) {
                        autoMapping[header] = 'email';
                    } else if (lowerHeader.includes('affiliation') || lowerHeader.includes('institution')) {
                        autoMapping[header] = 'affiliations';
                    } else if (lowerHeader.includes('contact')) {
                        autoMapping[header] = 'isContact';
                    } else if (lowerHeader.includes('type') || lowerHeader.includes('typ')) {
                        autoMapping[header] = 'type';
                    } else if (lowerHeader.includes('organization') || lowerHeader.includes('organisation')) {
                        autoMapping[header] = 'organizationName';
                    } else {
                        autoMapping[header] = 'ignore';
                    }
                });

                setColumnMapping(autoMapping);
                setStep('mapping');
            },
            error: (error) => {
                console.error('CSV parsing error:', error);
                alert('Fehler beim Lesen der CSV-Datei.');
            },
        });
    };

    const handleMappingChange = (csvColumn: string, targetField: string) => {
        setColumnMapping((prev) => ({
            ...prev,
            [csvColumn]: targetField,
        }));
    };

    const handlePreview = () => {
        const mapped: MappedRow[] = csvData.map((row) => {
            const mappedRow: MappedRow = { errors: [] };

            Object.entries(columnMapping).forEach(([csvColumn, targetField]) => {
                if (targetField === 'ignore') return;

                const value = row[csvColumn]?.trim();

                if (targetField === 'affiliations') {
                    // Split by comma for multiple affiliations
                    mappedRow.affiliations = value
                        ? value.split(',').map((aff) => aff.trim()).filter(Boolean)
                        : [];
                } else if (targetField === 'isContact') {
                    // Convert yes/no to boolean
                    mappedRow.isContact = ['yes', 'ja', 'true', '1'].includes(
                        value?.toLowerCase() || ''
                    );
                } else if (targetField === 'type') {
                    // Validate type
                    const normalizedType = value?.toLowerCase();
                    if (normalizedType === 'person' || normalizedType === 'organization') {
                        mappedRow.type = normalizedType as 'person' | 'organization';
                    } else {
                        mappedRow.type = 'person'; // Default
                    }
                } else {
                    (mappedRow as any)[targetField] = value || undefined;
                }
            });

            // Validation
            if (mappedRow.type === 'person' || !mappedRow.type) {
                if (!mappedRow.firstName && !mappedRow.lastName) {
                    mappedRow.errors?.push('Vorname oder Nachname erforderlich');
                }
            } else if (mappedRow.type === 'organization') {
                if (!mappedRow.organizationName) {
                    mappedRow.errors?.push('Organization Name erforderlich');
                }
            }

            // ORCID format validation
            if (mappedRow.orcid) {
                const orcidPattern = /^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/;
                if (!orcidPattern.test(mappedRow.orcid)) {
                    mappedRow.errors?.push('Ungültiges ORCID-Format');
                }
            }

            return mappedRow;
        });

        setMappedData(mapped);
        setStep('preview');
    };

    const handleImport = () => {
        // Filter out rows with errors
        const validRows = mappedData.filter((row) => !row.errors || row.errors.length === 0);
        
        if (validRows.length === 0) {
            alert('Keine gültigen Zeilen zum Importieren gefunden.');
            return;
        }

        onImport(validRows);
        handleClose();
    };

    const handleClose = () => {
        setOpen(false);
        // Reset state
        setTimeout(() => {
            setStep('upload');
            setCsvData([]);
            setCsvHeaders([]);
            setColumnMapping({});
            setMappedData([]);
            setFileName('');
        }, 300);
    };

    const validRowCount = mappedData.filter((row) => !row.errors || row.errors.length === 0).length;
    const errorRowCount = mappedData.length - validRowCount;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={triggerClassName}
                >
                    <Upload className="h-4 w-4 mr-2" />
                    Import CSV
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-4xl max-h-[85vh] flex flex-col">
                <DialogHeader>
                    <DialogTitle>
                        {type === 'author' ? 'Authors' : 'Contributors'} aus CSV importieren
                    </DialogTitle>
                    <DialogDescription>
                        {step === 'upload' && 'CSV-Datei hochladen'}
                        {step === 'mapping' && 'Spalten zuordnen'}
                        {step === 'preview' && 'Vorschau & Import'}
                    </DialogDescription>
                </DialogHeader>

                {/* Step 1: Upload */}
                {step === 'upload' && (
                    <div className="flex-1 flex flex-col gap-6">
                        {/* Example CSV Download */}
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <div className="flex items-start gap-3">
                                <FileText className="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" />
                                <div className="flex-1">
                                    <h4 className="font-medium text-sm text-blue-900 mb-1">
                                        Beispiel-CSV herunterladen
                                    </h4>
                                    <p className="text-sm text-blue-800 mb-3">
                                        Laden Sie eine Beispieldatei mit 4 Mustereinträgen herunter, 
                                        um die richtige Struktur Ihrer CSV-Datei zu sehen.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleDownloadExample}
                                        className="border-blue-300 hover:bg-blue-100"
                                    >
                                        <Download className="h-4 w-4 mr-2" />
                                        Beispiel-CSV herunterladen
                                    </Button>
                                </div>
                            </div>
                        </div>

                        {/* Upload Area */}
                        <div className="flex-1 flex flex-col items-center justify-center p-8 border-2 border-dashed rounded-lg">
                            <FileText className="h-16 w-16 text-muted-foreground mb-4" />
                            <Label
                                htmlFor="csv-upload"
                                className="cursor-pointer text-center"
                            >
                                <div className="text-lg font-medium mb-2">
                                    CSV-Datei auswählen
                                </div>
                                <div className="text-sm text-muted-foreground mb-4">
                                    Klicken oder Datei hierher ziehen
                                </div>
                            </Label>
                            <input
                                id="csv-upload"
                                type="file"
                                accept=".csv"
                                onChange={handleFileUpload}
                                className="hidden"
                            />
                            <Button type="button" onClick={() => document.getElementById('csv-upload')?.click()}>
                                <Upload className="h-4 w-4 mr-2" />
                                Datei auswählen
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 2: Column Mapping */}
                {step === 'mapping' && (
                    <div className="flex-1 overflow-y-auto space-y-4">
                        <div className="bg-muted/50 p-3 rounded-md">
                            <p className="text-sm">
                                <strong>Datei:</strong> {fileName} ({csvData.length} Zeilen)
                            </p>
                        </div>

                        <div className="space-y-3">
                            {csvHeaders.map((header) => (
                                <div key={header} className="flex items-center gap-4">
                                    <div className="w-48 font-medium text-sm truncate" title={header}>
                                        {header}
                                    </div>
                                    <div className="flex-1">
                                        <Select
                                            value={columnMapping[header] || 'ignore'}
                                            onValueChange={(value) => handleMappingChange(header, value)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {FIELD_OPTIONS.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="w-32 text-xs text-muted-foreground truncate">
                                        {csvData[0]?.[header] || '(leer)'}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-between pt-4 border-t">
                            <Button type="button" variant="outline" onClick={() => setStep('upload')}>
                                Zurück
                            </Button>
                            <Button type="button" onClick={handlePreview}>
                                Vorschau anzeigen
                            </Button>
                        </div>
                    </div>
                )}

                {/* Step 3: Preview */}
                {step === 'preview' && (
                    <div className="flex-1 flex flex-col gap-4 overflow-hidden">
                        <div className="bg-muted/50 p-3 rounded-md flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                    <span className="text-sm font-medium">{validRowCount} gültig</span>
                                </div>
                                {errorRowCount > 0 && (
                                    <div className="flex items-center gap-2">
                                        <AlertCircle className="h-4 w-4 text-red-600" />
                                        <span className="text-sm font-medium">{errorRowCount} Fehler</span>
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto border rounded-md">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 sticky top-0">
                                    <tr className="border-b">
                                        <th className="text-left p-2">#</th>
                                        <th className="text-left p-2">Status</th>
                                        <th className="text-left p-2">Type</th>
                                        <th className="text-left p-2">Name</th>
                                        <th className="text-left p-2">ORCID</th>
                                        <th className="text-left p-2">Email</th>
                                        <th className="text-left p-2">Affiliations</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {mappedData.map((row, index) => (
                                        <tr
                                            key={index}
                                            className={row.errors && row.errors.length > 0 ? 'bg-red-50' : ''}
                                        >
                                            <td className="p-2">{index + 1}</td>
                                            <td className="p-2">
                                                {row.errors && row.errors.length > 0 ? (
                                                    <div className="flex items-start gap-1">
                                                        <AlertCircle className="h-4 w-4 text-red-600 flex-shrink-0 mt-0.5" />
                                                        <div className="text-xs text-red-600">
                                                            {row.errors.join(', ')}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                                )}
                                            </td>
                                            <td className="p-2">{row.type || 'person'}</td>
                                            <td className="p-2">
                                                {row.type === 'organization'
                                                    ? row.organizationName
                                                    : `${row.firstName || ''} ${row.lastName || ''}`.trim() || '-'}
                                            </td>
                                            <td className="p-2 font-mono text-xs">{row.orcid || '-'}</td>
                                            <td className="p-2 text-xs">{row.email || '-'}</td>
                                            <td className="p-2 text-xs">
                                                {row.affiliations && row.affiliations.length > 0
                                                    ? row.affiliations.join(', ')
                                                    : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex justify-between pt-4 border-t">
                            <Button type="button" variant="outline" onClick={() => setStep('mapping')}>
                                Zurück
                            </Button>
                            <Button
                                type="button"
                                onClick={handleImport}
                                disabled={validRowCount === 0}
                            >
                                {validRowCount} {type === 'author' ? 'Authors' : 'Contributors'} importieren
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
