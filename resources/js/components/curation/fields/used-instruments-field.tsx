import { AlertCircle, ExternalLink, Fingerprint, Info, X } from 'lucide-react';
import { useMemo } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePid4instInstruments } from '@/hooks/use-pid4inst-instruments';
import type { InstrumentSelection } from '@/types';

interface UsedInstrumentsFieldProps {
    selectedInstruments: InstrumentSelection[];
    onChange: (instruments: InstrumentSelection[]) => void;
}

/**
 * Used Instruments Field Component
 *
 * Provides a searchable interface for selecting research instruments
 * from the PID4INST / b2inst registry. Instruments are identified by
 * persistent identifiers (PIDs) and will be exported as DataCite
 * relatedIdentifiers with relationType="IsCollectedBy".
 *
 * Features:
 * - Searchable combobox with instrument names
 * - Card-based display of selected instruments with PID badges
 * - Links to instrument landing pages
 * - Error handling and retry functionality
 * - Accessibility compliant (WCAG 2.1 AA)
 */
export default function UsedInstrumentsField({ selectedInstruments, onChange }: UsedInstrumentsFieldProps) {
    const { instruments, isLoading, error, refetch } = usePid4instInstruments();

    // Convert instruments to ComboboxOption format, excluding already selected
    const comboboxOptions: ComboboxOption[] = useMemo(() => {
        if (!instruments) return [];

        const selectedPids = new Set(selectedInstruments.map((inst) => inst.pid));
        return instruments
            .filter((inst) => !selectedPids.has(inst.pid))
            .map((inst) => ({
                value: inst.pid,
                label: inst.name,
                data: {
                    pid: inst.pid,
                    pidType: inst.pidType,
                    description: inst.description,
                    owners: inst.owners,
                    instrument: inst,
                },
            }));
    }, [instruments, selectedInstruments]);

    const handleSelectInstrument = (value: string | undefined) => {
        if (!value) return;

        const instrument = instruments?.find((inst) => inst.pid === value);
        if (instrument) {
            onChange([
                ...selectedInstruments,
                {
                    pid: instrument.pid,
                    pidType: instrument.pidType,
                    name: instrument.name,
                },
            ]);
        }
    };

    const handleRemoveInstrument = (pid: string) => {
        onChange(selectedInstruments.filter((inst) => inst.pid !== pid));
    };

    /**
     * Build the landing page URL for an instrument PID.
     * For Handle PIDs, resolve via hdl.handle.net.
     */
    const getInstrumentUrl = (pid: string, pidType: string): string => {
        if (pidType === 'Handle' || pidType === 'handle') {
            return `https://hdl.handle.net/${pid}`;
        }
        if (pidType === 'DOI' || pidType === 'doi') {
            return `https://doi.org/${pid}`;
        }
        // For URLs or unknown types, return as-is
        return pid.startsWith('http') ? pid : `https://hdl.handle.net/${pid}`;
    };

    return (
        <div className="space-y-4">
            {/* Info Banner */}
            <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/50">
                <div className="flex items-start gap-2">
                    <Info className="mt-0.5 h-4 w-4 flex-shrink-0 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                    <p className="text-sm text-blue-900 dark:text-blue-300">
                        Select the research instruments used to collect this dataset. Instruments are sourced from the{' '}
                        <a
                            href="https://b2inst.gwdg.de"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium underline hover:text-blue-700 dark:hover:text-blue-200"
                        >
                            b2inst Registry (PID4INST)
                        </a>
                        . Each instrument will be linked via its persistent identifier (Handle PID).
                    </p>
                </div>
            </div>

            {/* Error State */}
            {error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Unable to load instrument data</AlertTitle>
                    <AlertDescription className="flex items-center gap-2">
                        <span>{error}</span>
                        <Button variant="outline" size="sm" onClick={refetch} className="ml-2 h-7 px-2">
                            Retry
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {/* Instrument Selection */}
            <div className="space-y-3">
                <Label htmlFor="instrument-search" className="text-base font-semibold">
                    Add Instrument
                </Label>

                <Combobox
                    id="instrument-search"
                    options={comboboxOptions}
                    onChange={handleSelectInstrument}
                    placeholder="Search for an instrument (e.g., magnetometer, spectrometer...)"
                    searchPlaceholder="Type to search..."
                    emptyMessage="No instruments found"
                    disabled={isLoading || !!error}
                    clearable={false}
                    renderOption={(option) => (
                        <div className="flex flex-col gap-0.5">
                            <span className="font-medium">{option.label}</span>
                            {(option.data?.owners as string[])?.length > 0 && (
                                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                    <span>{(option.data?.owners as string[]).join(', ')}</span>
                                </div>
                            )}
                        </div>
                    )}
                />
            </div>

            {/* Loading Skeleton */}
            {isLoading && selectedInstruments.length === 0 && (
                <div className="space-y-3">
                    <Skeleton className="h-32 w-full" />
                </div>
            )}

            {/* Selected Instruments */}
            {selectedInstruments.length > 0 && (
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <Label className="text-base font-semibold">Selected Instruments ({selectedInstruments.length})</Label>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {selectedInstruments.map((inst) => (
                            <Card key={inst.pid} className="p-4">
                                <div className="space-y-3">
                                    {/* Instrument Name with Remove Button */}
                                    <div className="flex items-start justify-between gap-2">
                                        <h4 className="text-base leading-tight font-semibold">{inst.name}</h4>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleRemoveInstrument(inst.pid)}
                                            className="h-8 w-8 flex-shrink-0 p-0"
                                            aria-label={`Remove ${inst.name}`}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>

                                    {/* PID Badge */}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <a
                                            href={getInstrumentUrl(inst.pid, inst.pidType)}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex"
                                        >
                                            <Badge
                                                variant="outline"
                                                className="cursor-pointer text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                                            >
                                                <Fingerprint className="mr-1 h-3 w-3" />
                                                {inst.pidType}: {inst.pid}
                                                <ExternalLink className="ml-1 h-3 w-3" />
                                            </Badge>
                                        </a>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            {/* Empty State */}
            {!isLoading && !error && selectedInstruments.length === 0 && (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <p className="text-sm text-muted-foreground">
                        No instruments selected yet. Use the search above to add instruments used for data collection.
                    </p>
                </div>
            )}
        </div>
    );
}
