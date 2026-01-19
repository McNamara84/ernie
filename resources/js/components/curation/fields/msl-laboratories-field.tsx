import { AlertCircle, Building2, Info, X } from 'lucide-react';
import { useMemo } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useMSLLaboratories } from '@/hooks/use-msl-laboratories';
import type { MSLLaboratory } from '@/types';

interface MSLLaboratoriesFieldProps {
    selectedLaboratories: MSLLaboratory[];
    onChange: (laboratories: MSLLaboratory[]) => void;
}

/**
 * Extract ROR ID from full ROR URL
 * @example extractRorId('https://ror.org/04pp8hn57') => '04pp8hn57'
 */
function extractRorId(rorUrl: string): string {
    if (!rorUrl) return '';
    const match = rorUrl.match(/ror\.org\/([a-z0-9]+)/i);
    return match?.[1] ?? rorUrl;
}

/**
 * MSL Laboratories Field Component
 *
 * Provides a searchable interface for selecting multi-scale laboratories
 * associated with EPOS datasets. Data is sourced from Utrecht University's
 * MSL Vocabularies repository.
 *
 * Features:
 * - Searchable combobox with fuzzy matching (using shadcn/ui Combobox)
 * - Card-based display of selected laboratories
 * - ROR badge links to Research Organization Registry
 * - Keyboard navigation support
 * - Error handling and retry functionality
 * - Accessibility compliant (WCAG 2.1 AA)
 */
export default function MSLLaboratoriesField({ selectedLaboratories, onChange }: MSLLaboratoriesFieldProps) {
    const { laboratories, isLoading, error, refetch } = useMSLLaboratories();

    // Convert laboratories to ComboboxOption format
    const comboboxOptions: ComboboxOption[] = useMemo(() => {
        if (!laboratories) return [];

        const selectedIds = new Set(selectedLaboratories.map((lab) => lab.identifier));
        return laboratories
            .filter((lab) => !selectedIds.has(lab.identifier))
            .map((lab) => ({
                value: lab.identifier,
                label: lab.name,
                data: {
                    affiliation_name: lab.affiliation_name,
                    affiliation_ror: lab.affiliation_ror,
                    laboratory: lab,
                },
            }));
    }, [laboratories, selectedLaboratories]);

    const handleSelectLaboratory = (value: string | undefined) => {
        if (!value) return;

        const laboratory = laboratories?.find((lab) => lab.identifier === value);
        if (laboratory) {
            onChange([...selectedLaboratories, laboratory]);
        }
    };

    const handleRemoveLaboratory = (identifier: string) => {
        onChange(selectedLaboratories.filter((lab) => lab.identifier !== identifier));
    };

    return (
        <div className="space-y-4">
            {/* Info Banner */}
            <div className="rounded-lg border border-blue-200 bg-blue-50 p-3">
                <div className="flex items-start gap-2">
                    <Info className="mt-0.5 h-4 w-4 flex-shrink-0 text-blue-600" aria-hidden="true" />
                    <p className="text-sm text-blue-900">
                        Select the multi-scale laboratories associated with this dataset. Data is sourced from the{' '}
                        <a
                            href="https://github.com/UtrechtUniversity/msl_vocabularies"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="ml-1 font-medium underline hover:text-blue-700"
                        >
                            Utrecht University MSL Vocabularies
                        </a>
                        .
                    </p>
                </div>
            </div>

            {/* Error State */}
            {error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Unable to load laboratory data</AlertTitle>
                    <AlertDescription className="flex items-center gap-2">
                        <span>Please check your internet connection and try again.</span>
                        <Button variant="outline" size="sm" onClick={refetch} className="ml-2 h-7 px-2">
                            Retry
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {/* Laboratory Selection */}
            <div className="space-y-3">
                <Label htmlFor="msl-laboratory-search" className="text-base font-semibold">
                    Add Laboratory
                </Label>

                {/* Combobox */}
                <Combobox
                    id="msl-laboratory-search"
                    options={comboboxOptions}
                    onChange={handleSelectLaboratory}
                    placeholder="Search for a laboratory (e.g., TecLab, INGV, Utrecht...)"
                    searchPlaceholder="Type to search..."
                    emptyMessage="No laboratories found"
                    disabled={isLoading || !!error}
                    clearable={false}
                    renderOption={(option) => (
                        <div className="flex flex-col gap-0.5">
                            <span className="font-medium">{option.label}</span>
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Building2 className="h-3 w-3" />
                                <span>{(option.data?.affiliation_name as string) || ''}</span>
                            </div>
                        </div>
                    )}
                />
            </div>

            {/* Loading Skeleton */}
            {isLoading && selectedLaboratories.length === 0 && (
                <div className="space-y-3">
                    <Skeleton className="h-32 w-full" />
                </div>
            )}

            {/* Selected Laboratories */}
            {selectedLaboratories.length > 0 && (
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <Label className="text-base font-semibold">Selected Laboratories ({selectedLaboratories.length})</Label>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {selectedLaboratories.map((lab) => (
                            <Card key={lab.identifier} className="p-4">
                                <div className="space-y-3">
                                    {/* Laboratory Name with Remove Button */}
                                    <div className="flex items-start justify-between gap-2">
                                        <h4 className="text-base leading-tight font-semibold">🔬 {lab.name}</h4>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleRemoveLaboratory(lab.identifier)}
                                            className="h-8 w-8 p-0"
                                            aria-label={`Remove ${lab.name}`}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>

                                    {/* Affiliation Name */}
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Building2 className="h-4 w-4 flex-shrink-0" />
                                        <span>{lab.affiliation_name}</span>
                                    </div>

                                    {/* ROR Badge */}
                                    {lab.affiliation_ror ? (
                                        <a href={lab.affiliation_ror} target="_blank" rel="noopener noreferrer" className="inline-flex">
                                            <Badge
                                                variant="outline"
                                                className="cursor-pointer text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                                            >
                                                🏛️ ROR: {extractRorId(lab.affiliation_ror)}
                                            </Badge>
                                        </a>
                                    ) : (
                                        <Badge variant="outline" className="cursor-not-allowed text-xs opacity-50">
                                            ⚠️ No ROR ID available
                                        </Badge>
                                    )}
                                </div>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            {/* Empty State */}
            {!isLoading && !error && selectedLaboratories.length === 0 && (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <p className="text-sm text-muted-foreground">No laboratories selected yet. Use the search above to add laboratories.</p>
                </div>
            )}
        </div>
    );
}
