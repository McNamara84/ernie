import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';

import CoverageEntry from './CoverageEntry';
import type { SpatialTemporalCoverageEntry } from './types';

interface SpatialTemporalCoverageFieldProps {
    coverages: SpatialTemporalCoverageEntry[];
    apiKey: string;
    onChange: (coverages: SpatialTemporalCoverageEntry[]) => void;
    maxCoverages?: number;
}

/**
 * Creates an empty coverage entry with default values
 */
const createEmptyCoverage = (): SpatialTemporalCoverageEntry => {
    // Get user's timezone as default
    const defaultTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return {
        id: crypto.randomUUID(),
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: defaultTimezone,
        description: '',
    };
};

/**
 * Checks if a coverage entry can be considered complete enough to allow adding another
 */
const canAddCoverage = (
    coverages: SpatialTemporalCoverageEntry[],
    maxCoverages: number,
): boolean => {
    if (coverages.length >= maxCoverages) return false;
    if (coverages.length === 0) return true;

    const lastCoverage = coverages[coverages.length - 1];

    // Required fields: latMin, lonMin, startDate, endDate, timezone
    return !!(
        lastCoverage.latMin &&
        lastCoverage.lonMin &&
        lastCoverage.startDate &&
        lastCoverage.endDate &&
        lastCoverage.timezone
    );
};

/**
 * Main component for managing spatial and temporal coverage entries
 */
export default function SpatialTemporalCoverageField({
    coverages,
    apiKey,
    onChange,
    maxCoverages = 99,
}: SpatialTemporalCoverageFieldProps) {
    const handleEntryChange = (
        index: number,
        field: keyof SpatialTemporalCoverageEntry,
        value: string,
    ) => {
        const updated = [...coverages];
        updated[index] = { ...updated[index], [field]: value };
        onChange(updated);
    };

    const handleEntryBatchChange = (
        index: number,
        updates: Partial<SpatialTemporalCoverageEntry>,
    ) => {
        const updated = [...coverages];
        updated[index] = { ...updated[index], ...updates };
        onChange(updated);
    };

    const handleAddCoverage = () => {
        if (canAddCoverage(coverages, maxCoverages)) {
            onChange([...coverages, createEmptyCoverage()]);
        }
    };

    const handleRemoveCoverage = (index: number) => {
        const updated = coverages.filter((_, i) => i !== index);
        onChange(updated);
    };

    return (
        <div className="space-y-4">
            {/* Coverage Entries */}
            {coverages.length > 0 ? (
                coverages.map((coverage, index) => (
                    <CoverageEntry
                        key={coverage.id}
                        entry={coverage}
                        index={index}
                        apiKey={apiKey}
                        isFirst={index === 0}
                        onChange={(field, value) => handleEntryChange(index, field, value)}
                        onBatchChange={(updates) => handleEntryBatchChange(index, updates)}
                        onRemove={() => handleRemoveCoverage(index)}
                    />
                ))
            ) : (
                <div className="text-center py-8 text-muted-foreground border-2 border-dashed rounded-lg">
                    <p className="mb-4">No spatial and temporal coverage entries yet.</p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handleAddCoverage}
                    >
                        <Plus className="h-4 w-4 mr-2" />
                        Add First Coverage Entry
                    </Button>
                </div>
            )}

            {/* Add Button */}
            {coverages.length > 0 && canAddCoverage(coverages, maxCoverages) && (
                <div className="flex justify-center">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handleAddCoverage}
                    >
                        <Plus className="h-4 w-4 mr-2" />
                        Add Coverage Entry
                    </Button>
                </div>
            )}

            {/* Max limit reached message */}
            {coverages.length >= maxCoverages && (
                <p className="text-sm text-muted-foreground text-center">
                    Maximum number of coverage entries ({maxCoverages}) reached.
                </p>
            )}

            {/* Help text */}
            {coverages.length > 0 && !canAddCoverage(coverages, maxCoverages) && coverages.length < maxCoverages && (
                <p className="text-sm text-muted-foreground text-center">
                    Complete the required fields in the last entry to add more coverage entries.
                </p>
            )}
        </div>
    );
}
