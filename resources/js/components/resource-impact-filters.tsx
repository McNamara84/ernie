import { Search, X } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ResourceImpactDatacenterOption, ResourceImpactFilterState } from '@/types/resource-impact-filters';
import { validateDOIFormat } from '@/utils/validation-rules';

interface ResourceImpactFiltersProps {
    filters: ResourceImpactFilterState;
    datacenterOptions: ResourceImpactDatacenterOption[];
    onChange: (filters: ResourceImpactFilterState) => void;
    disabled?: boolean;
}

export function ResourceImpactFilters({ filters, datacenterOptions, onChange, disabled = false }: ResourceImpactFiltersProps) {
    const [doiInput, setDoiInput] = useState(filters.doi ?? '');
    const [doiError, setDoiError] = useState<string | null>(null);
    const selectedDatacenter = useMemo(
        () => datacenterOptions.find((datacenter) => datacenter.id === filters.datacenter_id),
        [datacenterOptions, filters.datacenter_id],
    );

    useEffect(() => {
        setDoiInput(filters.doi ?? '');
        setDoiError(null);
    }, [filters.doi]);

    function applyDoi(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const value = doiInput.trim();

        if (value === '') {
            setDoiError(null);
            onChange({ ...filters, doi: null });
            return;
        }

        const validation = validateDOIFormat(value);

        if (!validation.isValid || validation.normalizedDOI === undefined) {
            setDoiError(validation.error ?? 'Enter a valid DOI.');
            return;
        }

        setDoiError(null);
        onChange({ ...filters, doi: validation.normalizedDOI.toLowerCase() });
    }

    function clearDoi() {
        setDoiInput('');
        setDoiError(null);
        onChange({ ...filters, doi: null });
    }

    function changeDatacenter(value: string) {
        onChange({
            ...filters,
            datacenter_id: value === 'all' ? null : Number(value),
        });
    }

    function clearAll() {
        setDoiInput('');
        setDoiError(null);
        onChange({ doi: null, datacenter_id: null });
    }

    const hasActiveFilters = filters.doi !== null || filters.datacenter_id !== null;

    return (
        <div className="space-y-3 rounded-lg border bg-card p-4" data-testid="resource-impact-filters">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                <form className="min-w-0 flex-1 space-y-1.5" onSubmit={applyDoi} noValidate>
                    <Label htmlFor="resource-impact-doi">DOI</Label>
                    <div className="flex gap-2">
                        <div className="relative min-w-0 flex-1">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                            <Input
                                id="resource-impact-doi"
                                type="search"
                                value={doiInput}
                                placeholder="10.xxxx/... or https://doi.org/10.xxxx/..."
                                className="pr-9 pl-9 font-mono"
                                aria-invalid={doiError !== null}
                                aria-describedby={doiError === null ? undefined : 'resource-impact-doi-error'}
                                disabled={disabled}
                                onChange={(event) => {
                                    setDoiInput(event.target.value);
                                    if (doiError !== null) setDoiError(null);
                                }}
                            />
                            {doiInput !== '' && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    className="absolute top-1/2 right-1 -translate-y-1/2"
                                    aria-label="Clear DOI filter"
                                    disabled={disabled}
                                    onClick={clearDoi}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                        <Button type="submit" variant="outline" disabled={disabled}>
                            Apply
                        </Button>
                    </div>
                    {doiError !== null && (
                        <p id="resource-impact-doi-error" role="alert" className="text-sm text-destructive">
                            {doiError}
                        </p>
                    )}
                </form>

                <div className="space-y-1.5 lg:w-72">
                    <Label htmlFor="resource-impact-datacenter">Datacenter</Label>
                    <Select
                        value={filters.datacenter_id === null ? 'all' : String(filters.datacenter_id)}
                        onValueChange={changeDatacenter}
                        disabled={disabled}
                    >
                        <SelectTrigger id="resource-impact-datacenter" className="w-full" aria-label="Filter by datacenter">
                            <SelectValue placeholder="All Datacenters" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Datacenters</SelectItem>
                            {datacenterOptions.map((datacenter) => (
                                <SelectItem key={datacenter.id} value={String(datacenter.id)}>
                                    {datacenter.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {hasActiveFilters && (
                    <Button type="button" variant="ghost" disabled={disabled} onClick={clearAll}>
                        Clear all
                    </Button>
                )}
            </div>

            {hasActiveFilters && (
                <div className="flex flex-wrap items-center gap-2" aria-label="Active filters">
                    <span className="text-sm text-muted-foreground">Active filters:</span>
                    {filters.doi !== null && <Badge variant="secondary">DOI: {filters.doi}</Badge>}
                    {filters.datacenter_id !== null && (
                        <Badge variant="secondary">Datacenter: {selectedDatacenter?.name ?? `#${filters.datacenter_id}`}</Badge>
                    )}
                </div>
            )}
        </div>
    );
}
