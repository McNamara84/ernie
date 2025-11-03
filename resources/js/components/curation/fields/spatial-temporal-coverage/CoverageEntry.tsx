import { ChevronDown, ChevronUp, Trash2 } from 'lucide-react';
import React, { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

import CoordinateInputs from './CoordinateInputs';
import MapPicker from './MapPicker';
import TemporalInputs from './TemporalInputs';
import type { CoordinateBounds, SpatialTemporalCoverageEntry } from './types';

interface CoverageEntryProps {
    entry: SpatialTemporalCoverageEntry;
    index: number;
    apiKey: string;
    isFirst: boolean;
    onChange: (field: keyof SpatialTemporalCoverageEntry, value: string) => void;
    onBatchChange: (updates: Partial<SpatialTemporalCoverageEntry>) => void;
    onRemove: () => void;
}

/**
 * Formats coordinates for preview display
 */
const formatCoordinates = (entry: SpatialTemporalCoverageEntry): string => {
    if (!entry.latMin || !entry.lonMin) return 'No coordinates set';

    if (entry.latMax && entry.lonMax) {
        return `(${entry.latMin}, ${entry.lonMin}) to (${entry.latMax}, ${entry.lonMax})`;
    }

    return `(${entry.latMin}, ${entry.lonMin})`;
};

/**
 * Formats date range for preview display
 */
const formatDateRange = (entry: SpatialTemporalCoverageEntry): string => {
    if (!entry.startDate && !entry.endDate) return 'No dates set';

    const start = entry.startDate
        ? entry.startTime
            ? `${entry.startDate} ${entry.startTime}`
            : entry.startDate
        : 'Not set';
    const end = entry.endDate
        ? entry.endTime
            ? `${entry.endDate} ${entry.endTime}`
            : entry.endDate
        : 'Not set';

    return `${start} to ${end}`;
};

/**
 * Checks if entry has any data
 */
const hasData = (entry: SpatialTemporalCoverageEntry): boolean => {
    return !!(
        entry.latMin ||
        entry.lonMin ||
        entry.startDate ||
        entry.endDate ||
        entry.description
    );
};

export default function CoverageEntry({
    entry,
    index,
    apiKey,
    isFirst,
    onChange,
    onBatchChange,
    onRemove,
}: CoverageEntryProps) {
    const [isExpanded, setIsExpanded] = useState(true);

    const handlePointSelected = (lat: number, lng: number) => {
        // Update all fields in one batch
        const latMinStr = lat.toFixed(6);
        const lonMinStr = lng.toFixed(6);
        
        console.log('Point selected:', { lat: latMinStr, lng: lonMinStr });
        
        onBatchChange({
            latMin: latMinStr,
            lonMin: lonMinStr,
            latMax: '',
            lonMax: '',
        });
    };

    const handleRectangleSelected = (bounds: CoordinateBounds) => {
        // Update all fields in one batch
        const latMinStr = bounds.south.toFixed(6);
        const latMaxStr = bounds.north.toFixed(6);
        const lonMinStr = bounds.west.toFixed(6);
        const lonMaxStr = bounds.east.toFixed(6);
        
        console.log('Rectangle selected:', { 
            latMin: latMinStr, 
            latMax: latMaxStr, 
            lonMin: lonMinStr, 
            lonMax: lonMaxStr 
        });
        
        onBatchChange({
            latMin: latMinStr,
            latMax: latMaxStr,
            lonMin: lonMinStr,
            lonMax: lonMaxStr,
        });
    };

    const handleCoordinateChange = (
        field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax',
        value: string,
    ) => {
        onChange(field, value);
    };

    const handleTemporalChange = (
        field: 'startDate' | 'endDate' | 'startTime' | 'endTime' | 'timezone',
        value: string,
    ) => {
        onChange(field, value);
    };

    return (
        <Card className="w-full">
            <CardHeader
                className="cursor-pointer hover:bg-muted/50 transition-colors"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <div className="flex items-center justify-between">
                    <div className="flex-1">
                        <h3 className="text-lg font-semibold">
                            Coverage Entry #{index + 1}
                        </h3>
                        {!isExpanded && hasData(entry) && (
                            <div className="mt-2 space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    📍 {formatCoordinates(entry)}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    🕐 {formatDateRange(entry)}
                                </p>
                                {entry.description && (
                                    <p className="text-sm text-muted-foreground truncate">
                                        {entry.description}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                    <div className="flex gap-2 ml-4">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={(e) => {
                                e.stopPropagation();
                                setIsExpanded(!isExpanded);
                            }}
                        >
                            {isExpanded ? (
                                <ChevronUp className="h-4 w-4" />
                            ) : (
                                <ChevronDown className="h-4 w-4" />
                            )}
                        </Button>
                        {!isFirst && (
                            <Button
                                type="button"
                                variant="destructive"
                                size="icon"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onRemove();
                                }}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>

            {isExpanded && (
                <CardContent className="space-y-6">
                    {/* Map and Inputs in Grid Layout */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Left Column: Map */}
                        <div className="space-y-4">
                            <MapPicker
                                apiKey={apiKey}
                                latMin={entry.latMin}
                                lonMin={entry.lonMin}
                                latMax={entry.latMax}
                                lonMax={entry.lonMax}
                                onPointSelected={handlePointSelected}
                                onRectangleSelected={handleRectangleSelected}
                            />
                        </div>

                        {/* Right Column: Coordinates and Temporal */}
                        <div className="space-y-4">
                            <CoordinateInputs
                                latMin={entry.latMin}
                                lonMin={entry.lonMin}
                                latMax={entry.latMax}
                                lonMax={entry.lonMax}
                                onChange={handleCoordinateChange}
                                showLabels={true}
                            />

                            <TemporalInputs
                                startDate={entry.startDate || ''}
                                endDate={entry.endDate || ''}
                                startTime={entry.startTime || ''}
                                endTime={entry.endTime || ''}
                                timezone={entry.timezone || 'UTC'}
                                onChange={handleTemporalChange}
                                showLabels={true}
                            />
                        </div>
                    </div>

                    {/* Description (Full Width) */}
                    <div className="space-y-2">
                        <Label htmlFor={`description-${entry.id}`}>
                            Description (optional)
                        </Label>
                        <Textarea
                            id={`description-${entry.id}`}
                            value={entry.description}
                            onChange={(e) => onChange('description', e.target.value)}
                            placeholder="e.g., Deep drilling campaign at site XYZ..."
                            rows={3}
                            className="resize-none"
                        />
                        <p className="text-xs text-muted-foreground">
                            Provide additional context about this spatial and temporal coverage.
                        </p>
                    </div>
                </CardContent>
            )}
        </Card>
    );
}
