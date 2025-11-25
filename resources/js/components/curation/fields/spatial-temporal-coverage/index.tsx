import { Plus } from 'lucide-react';
import React, { useEffect } from 'react';

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
 * Normalizes coverage entries to ensure they have a type field
 * Handles legacy data from backend that may not have type set
 */
const normalizeCoverage = (coverage: SpatialTemporalCoverageEntry): SpatialTemporalCoverageEntry => {
    // If type is already set, return as-is
    if (coverage.type) {
        return coverage;
    }

    // Detect type based on existing data
    let detectedType: 'point' | 'box' | 'polygon' = 'point';

    if (coverage.polygonPoints && coverage.polygonPoints.length > 0) {
        detectedType = 'polygon';
    } else if (coverage.latMax && coverage.lonMax) {
        detectedType = 'box';
    }

    return {
        ...coverage,
        type: detectedType,
    };
};

/**
 * Creates an empty coverage entry with default values
 */
const createEmptyCoverage = (): SpatialTemporalCoverageEntry => {
    // Get user's timezone as default
    const defaultTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return {
        id: crypto.randomUUID(),
        type: 'point', // Default to point coverage
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        polygonPoints: undefined,
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

    // For polygon type: require at least 3 points
    if (lastCoverage.type === 'polygon') {
        return !!(
            lastCoverage.polygonPoints &&
            lastCoverage.polygonPoints.length >= 3
        );
    }

    // For point/box type: require latMin and lonMin
    // Temporal fields (startDate, endDate, timezone) are now optional
    return !!(
        lastCoverage.latMin &&
        lastCoverage.lonMin
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
    // Normalize coverages on mount if they don't have type field
    // This runs only once with the initial coverages prop value to handle legacy data
    useEffect(() => {
        const needsNormalization = coverages.some(c => !c.type);
        if (needsNormalization) {
            const normalized = coverages.map(normalizeCoverage);
            onChange(normalized);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []); // Intentionally empty: only normalize initial prop value on mount

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
