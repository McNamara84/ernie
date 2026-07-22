import { AlertCircle, Building2, Globe2, Info, Microscope, TriangleAlert, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { getValidRorUrl, toMslLaboratorySelection } from '@/components/curation/utils/msl-laboratories';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useMSLLaboratories } from '@/hooks/use-msl-laboratories';
import type { MSLLaboratory, MSLLaboratoryVocabularyEntry } from '@/types';

interface MSLLaboratoriesFieldProps {
    selectedLaboratories: MSLLaboratory[];
    onChange: (laboratories: MSLLaboratory[]) => void;
    isVocabularyAvailable?: boolean;
}

const ALL_FILTER_VALUE = '';

function extractRorId(rorUrl: string): string {
    return rorUrl.replace(/^https:\/\/ror\.org\//i, '');
}

export default function MSLLaboratoriesField({ selectedLaboratories, onChange, isVocabularyAvailable = true }: MSLLaboratoriesFieldProps) {
    const { laboratories, version, lastUpdated, isLoading, isUnavailable, error, refetch } = useMSLLaboratories({
        enabled: isVocabularyAvailable,
    });
    const [scientificDomain, setScientificDomain] = useState(ALL_FILTER_VALUE);
    const [country, setCountry] = useState(ALL_FILTER_VALUE);

    const vocabularyByIdentifier = useMemo(
        () => new Map((laboratories ?? []).map((laboratory) => [laboratory.identifier, laboratory])),
        [laboratories],
    );

    const scientificDomains = useMemo(
        () => [...new Set((laboratories ?? []).map((laboratory) => laboratory.scientific_domain))].sort((left, right) => left.localeCompare(right)),
        [laboratories],
    );
    const countries = useMemo(
        () => [...new Set((laboratories ?? []).map((laboratory) => laboratory.country))].sort((left, right) => left.localeCompare(right)),
        [laboratories],
    );

    const comboboxOptions: ComboboxOption[] = useMemo(() => {
        const selectedIds = new Set(selectedLaboratories.map((laboratory) => laboratory.identifier));

        return (laboratories ?? [])
            .filter((laboratory) => !selectedIds.has(laboratory.identifier))
            .filter((laboratory) => scientificDomain === ALL_FILTER_VALUE || laboratory.scientific_domain === scientificDomain)
            .filter((laboratory) => country === ALL_FILTER_VALUE || laboratory.country === country)
            .sort((left, right) => left.display_name.localeCompare(right.display_name))
            .map((laboratory) => ({
                value: laboratory.identifier,
                label: laboratory.display_name,
                keywords: [
                    laboratory.name,
                    laboratory.display_name,
                    laboratory.affiliation_name,
                    laboratory.scientific_domain,
                    laboratory.country,
                    laboratory.identifier,
                ],
                data: { laboratory },
            }));
    }, [country, laboratories, scientificDomain, selectedLaboratories]);

    const handleSelectLaboratory = (identifier: string | undefined) => {
        if (!identifier) return;

        const laboratory = vocabularyByIdentifier.get(identifier);
        if (laboratory) {
            onChange([...selectedLaboratories, toMslLaboratorySelection(laboratory)]);
        }
    };

    const handleRemoveLaboratory = (identifier: string) => {
        onChange(selectedLaboratories.filter((laboratory) => laboratory.identifier !== identifier));
    };

    const canAddLaboratories = isVocabularyAvailable && !isUnavailable;

    return (
        <div className="space-y-4">
            <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/40">
                <div className="flex items-start gap-2">
                    <Info className="mt-0.5 h-4 w-4 flex-shrink-0 text-blue-600" aria-hidden="true" />
                    <div className="space-y-1 text-sm text-blue-900 dark:text-blue-100">
                        <p>
                            Select the multi-scale laboratories associated with this dataset. ERNIE uses a locally managed copy of the{' '}
                            <a
                                href="https://github.com/UtrechtUniversity/msl_vocabularies"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium underline hover:text-blue-700"
                            >
                                Utrecht University MSL Vocabularies
                            </a>
                            .
                        </p>
                        {version && (
                            <p className="text-xs">
                                Vocabulary version {version}
                                {lastUpdated ? ` · updated ${new Date(lastUpdated).toLocaleDateString()}` : ''}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {!canAddLaboratories && (
                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertTitle>Laboratory vocabulary unavailable</AlertTitle>
                    <AlertDescription>
                        New laboratories cannot be added right now. Existing selections remain visible and will still be saved.
                    </AlertDescription>
                </Alert>
            )}

            {error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Unable to load laboratory data</AlertTitle>
                    <AlertDescription className="flex items-center gap-2">
                        <span>The local MSL laboratory vocabulary could not be loaded.</span>
                        <Button variant="outline" size="sm" onClick={refetch} className="ml-2 h-7 px-2">
                            Retry
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {canAddLaboratories && (
                <div className="space-y-3">
                    <Label htmlFor="msl-laboratory-search" className="text-base font-semibold">
                        Add Laboratory
                    </Label>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="msl-scientific-domain-filter" className="text-xs text-muted-foreground">
                                Scientific domain
                            </Label>
                            <select
                                id="msl-scientific-domain-filter"
                                value={scientificDomain}
                                onChange={(event) => setScientificDomain(event.target.value)}
                                disabled={isLoading || !!error}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value={ALL_FILTER_VALUE}>All scientific domains</option>
                                {scientificDomains.map((domain) => (
                                    <option key={domain} value={domain}>
                                        {domain}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="msl-country-filter" className="text-xs text-muted-foreground">
                                Country
                            </Label>
                            <select
                                id="msl-country-filter"
                                value={country}
                                onChange={(event) => setCountry(event.target.value)}
                                disabled={isLoading || !!error}
                                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value={ALL_FILTER_VALUE}>All countries</option>
                                {countries.map((countryName) => (
                                    <option key={countryName} value={countryName}>
                                        {countryName}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <Combobox
                        id="msl-laboratory-search"
                        options={comboboxOptions}
                        onChange={handleSelectLaboratory}
                        placeholder="Search by laboratory, institution, domain, country, or identifier"
                        searchPlaceholder="Search laboratories..."
                        emptyMessage="No laboratories match the current search and filters"
                        disabled={isLoading || !!error}
                        clearable={false}
                        renderOption={(option) => {
                            const laboratory = option.data?.laboratory as MSLLaboratoryVocabularyEntry;

                            return (
                                <div className="min-w-0 space-y-1">
                                    <span className="block font-medium">{laboratory.display_name}</span>
                                    <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                        <Building2 className="h-3 w-3" aria-hidden="true" />
                                        <span>{laboratory.affiliation_name}</span>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        <Badge variant="secondary" className="text-[0.7rem]">
                                            {laboratory.scientific_domain}
                                        </Badge>
                                        <Badge variant="outline" className="text-[0.7rem]">
                                            {laboratory.country}
                                        </Badge>
                                    </div>
                                </div>
                            );
                        }}
                    />
                </div>
            )}

            {isLoading && selectedLaboratories.length === 0 && (
                <div className="space-y-3">
                    <Skeleton className="h-32 w-full" />
                </div>
            )}

            {selectedLaboratories.length > 0 && (
                <div className="space-y-4">
                    <Label className="text-base font-semibold">Selected Laboratories ({selectedLaboratories.length})</Label>

                    <div className="grid gap-4 md:grid-cols-2">
                        {selectedLaboratories.map((selection) => {
                            const currentEntry = vocabularyByIdentifier.get(selection.identifier);
                            const laboratory = currentEntry ? { ...currentEntry, ...selection } : selection;
                            const rorUrl = getValidRorUrl(selection.affiliation_ror);
                            const isHistorical = laboratories !== null && !currentEntry;

                            return (
                                <Card key={selection.identifier} className="p-4">
                                    <div className="space-y-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <h4 className="text-base leading-tight font-semibold">{currentEntry?.display_name ?? laboratory.name}</h4>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => handleRemoveLaboratory(selection.identifier)}
                                                className="h-8 w-8 p-0"
                                                aria-label={`Remove ${currentEntry?.display_name ?? selection.name} (${selection.identifier})`}
                                            >
                                                <X className="h-4 w-4" aria-hidden="true" />
                                            </Button>
                                        </div>

                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Building2 className="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                                            <span>{laboratory.affiliation_name || 'Affiliation not available'}</span>
                                        </div>

                                        {currentEntry && (
                                            <div className="flex flex-wrap gap-2">
                                                <Badge variant="secondary">
                                                    <Microscope className="mr-1 h-3 w-3" aria-hidden="true" />
                                                    {currentEntry.scientific_domain}
                                                </Badge>
                                                <Badge variant="outline">
                                                    <Globe2 className="mr-1 h-3 w-3" aria-hidden="true" />
                                                    {currentEntry.country}
                                                </Badge>
                                            </div>
                                        )}

                                        {rorUrl ? (
                                            <a href={rorUrl} target="_blank" rel="noopener noreferrer" className="inline-flex">
                                                <Badge
                                                    variant="outline"
                                                    className="cursor-pointer text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                                                >
                                                    ROR: {extractRorId(rorUrl)}
                                                </Badge>
                                            </a>
                                        ) : (
                                            <Badge variant="outline" className="text-xs opacity-60">
                                                No valid ROR ID available
                                            </Badge>
                                        )}

                                        {isHistorical && (
                                            <div className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                                                <TriangleAlert className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" aria-hidden="true" />
                                                <span>Not present in the current MSL vocabulary</span>
                                            </div>
                                        )}

                                        <p className="text-xs break-all text-muted-foreground">
                                            Lab ID: <code>{selection.identifier}</code>
                                        </p>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                </div>
            )}

            {!isLoading && !error && selectedLaboratories.length === 0 && (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <p className="text-sm text-muted-foreground">No laboratories selected yet.</p>
                </div>
            )}
        </div>
    );
}
