import { MapPin, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { isCompleteCoverageDate } from '@/lib/temporal-coverage';

import CoverageEntry from './CoverageEntry';
import type { SpatialTemporalCoverageEntry } from './types';

interface SpatialTemporalCoverageFieldProps {
    coverages: SpatialTemporalCoverageEntry[];
    apiKey: string;
    onChange: (coverages: SpatialTemporalCoverageEntry[]) => void;
}

/**
 * Normalizes coverage entries to ensure they have a type field
 * Handles legacy data from backend that may not have type set
 */
const normalizeCoverage = (coverage: SpatialTemporalCoverageEntry): SpatialTemporalCoverageEntry => {
    // Detect type based on existing data
    let detectedType: 'point' | 'box' | 'polygon' | 'line' = coverage.type || 'point';

    if (!coverage.type) {
        if (coverage.polygonPoints && coverage.polygonPoints.length > 0) {
            detectedType = 'polygon';
        } else if (coverage.latMax && coverage.lonMax) {
            detectedType = 'box';
        }
    }

    return {
        ...coverage,
        type: detectedType,
        temporalMode: coverage.temporalMode ?? 'interval',
    };
};

/**
 * Creates an empty coverage entry with default values
 */
const createEmptyCoverage = (): SpatialTemporalCoverageEntry => {
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
        temporalMode: 'interval',
        startTime: '',
        endTime: '',
        timezone: '',
        description: '',
    };
};

/**
 * Checks if a coverage entry can be considered complete enough to allow adding another
 */
export const canAddCoverage = (coverages: SpatialTemporalCoverageEntry[]): boolean => {
    if (coverages.length === 0) return true;

    const lastCoverage = coverages[coverages.length - 1];

    if (
        (lastCoverage.startTime && !isCompleteCoverageDate(lastCoverage.startDate)) ||
        (lastCoverage.endTime && !isCompleteCoverageDate(lastCoverage.endDate)) ||
        (lastCoverage.timezone && !lastCoverage.startDate && !lastCoverage.endDate)
    ) {
        return false;
    }

    const hasTemporalOrDescription = !!(
        lastCoverage.startDate ||
        lastCoverage.endDate ||
        lastCoverage.startTime ||
        lastCoverage.endTime ||
        lastCoverage.timezone ||
        lastCoverage.description
    );

    if (lastCoverage.type === 'polygon' || lastCoverage.type === 'line') {
        const pointCount = lastCoverage.polygonPoints?.length ?? 0;
        const requiredCount = lastCoverage.type === 'polygon' ? 3 : 2;
        return pointCount === 0 ? hasTemporalOrDescription : pointCount >= requiredCount;
    }

    const coordinates = [lastCoverage.latMin, lastCoverage.lonMin, lastCoverage.latMax, lastCoverage.lonMax];
    const hasCoordinates = coordinates.some(Boolean);
    if (!hasCoordinates) return hasTemporalOrDescription;

    return lastCoverage.type === 'box' ? coordinates.every(Boolean) : !!(lastCoverage.latMin && lastCoverage.lonMin);
};

/**
 * Main component for managing spatial and temporal coverage entries
 */
export default function SpatialTemporalCoverageField({ coverages, apiKey, onChange }: SpatialTemporalCoverageFieldProps) {
    const [expandedCoverageIds, setExpandedCoverageIds] = useState<Set<string>>(() => new Set());

    // Normalize coverages on mount if they don't have type field
    // This runs only once with the initial coverages prop value to handle legacy data
    useEffect(() => {
        const needsNormalization = coverages.some((c) => !c.type || !c.temporalMode);
        if (needsNormalization) {
            const normalized = coverages.map(normalizeCoverage);
            onChange(normalized);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []); // Intentionally empty: only normalize initial prop value on mount

    const handleEntryChange = (index: number, field: keyof SpatialTemporalCoverageEntry, value: string) => {
        const updated = [...coverages];
        updated[index] = { ...updated[index], [field]: value };
        onChange(updated);
    };

    const handleEntryBatchChange = (index: number, updates: Partial<SpatialTemporalCoverageEntry>) => {
        const updated = [...coverages];
        updated[index] = { ...updated[index], ...updates };
        onChange(updated);
    };

    const handleAddCoverage = () => {
        if (canAddCoverage(coverages)) {
            const newCoverage = createEmptyCoverage();

            setExpandedCoverageIds((current) => {
                const next = new Set(current);
                next.add(newCoverage.id);
                return next;
            });

            onChange([...coverages, newCoverage]);
        }
    };

    const handleRemoveCoverage = (index: number) => {
        const removedCoverageId = coverages[index]?.id;
        const updated = coverages.filter((_, i) => i !== index);

        if (removedCoverageId) {
            setExpandedCoverageIds((current) => {
                const next = new Set(current);
                next.delete(removedCoverageId);
                return next;
            });
        }

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
                        initiallyExpanded={expandedCoverageIds.has(coverage.id)}
                    />
                ))
            ) : (
                <EmptyState
                    icon={<MapPin className="h-8 w-8" />}
                    title="No coverage entries yet"
                    description="Define the geographic and temporal scope of your dataset. Supports point locations, bounding boxes, polygons, and lines."
                    action={{
                        label: 'Add Coverage Entry',
                        onClick: handleAddCoverage,
                    }}
                    data-testid="coverage-empty-state"
                />
            )}

            {/* Add Button */}
            {coverages.length > 0 && canAddCoverage(coverages) && (
                <div className="flex justify-center">
                    <Button type="button" variant="outline" onClick={handleAddCoverage}>
                        <Plus className="mr-2 h-4 w-4" />
                        Add Coverage Entry
                    </Button>
                </div>
            )}

            {/* Help text */}
            {coverages.length > 0 && !canAddCoverage(coverages) && (
                <p className="text-center text-sm text-muted-foreground">
                    Complete the required fields in the last entry to add more coverage entries.
                </p>
            )}
        </div>
    );
}
