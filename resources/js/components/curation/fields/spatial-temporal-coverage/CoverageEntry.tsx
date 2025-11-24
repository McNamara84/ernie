import { ChevronDown, ChevronUp, Trash2 } from 'lucide-react';
import React, { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

import BoxForm from './BoxForm';
import PointForm from './PointForm';
import PolygonForm from './PolygonForm';
import TemporalInputs from './TemporalInputs';
import type { CoverageType, SpatialTemporalCoverageEntry } from './types';

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
    if (entry.type === 'polygon') {
        if (!entry.polygonPoints || entry.polygonPoints.length === 0) {
            return 'Polygon: No points set';
        }
        return `Polygon: ${entry.polygonPoints.length} points`;
    }

    if (!entry.latMin || !entry.lonMin) return 'No coordinates set';

    if (entry.type === 'box' && entry.latMax && entry.lonMax) {
        return `Box: (${entry.latMin}, ${entry.lonMin}) to (${entry.latMax}, ${entry.lonMax})`;
    }

    return `Point: (${entry.latMin}, ${entry.lonMin})`;
};

/**
 * Helper function to format a single date/time value
 */
const formatDateTimeValue = (date: string, time: string): string => {
    if (!date) return '';

    if (time) {
        return `${date} ${time}`;
    }

    return date;
};

/**
 * Formats date range for preview display
 */
const formatDateRange = (entry: SpatialTemporalCoverageEntry): string => {
    const startFormatted = formatDateTimeValue(entry.startDate, entry.startTime);
    const endFormatted = formatDateTimeValue(entry.endDate, entry.endTime);

    // If neither date is set
    if (!startFormatted && !endFormatted) {
        return 'No dates set';
    }

    // If only start date is set
    if (startFormatted && !endFormatted) {
        return `Start: ${startFormatted}`;
    }

    // If only end date is set
    if (!startFormatted && endFormatted) {
        return `End: ${endFormatted}`;
    }

    // Both dates are set
    return `${startFormatted} to ${endFormatted}`;
};

/**
 * Checks if entry has any data
 */
const hasData = (entry: SpatialTemporalCoverageEntry): boolean => {
    // Check polygon points
    if (entry.type === 'polygon' && entry.polygonPoints && entry.polygonPoints.length > 0) {
        return true;
    }

    // Check point/box coordinates
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

    const handleTypeChange = (newType: CoverageType) => {
        // Clear inappropriate data when switching types
        if (newType === 'polygon') {
            onBatchChange({
                type: newType,
                latMin: '',
                latMax: '',
                lonMin: '',
                lonMax: '',
                polygonPoints: [],
            });
        } else if (newType === 'point') {
            onBatchChange({
                type: newType,
                latMax: '',
                lonMax: '',
                polygonPoints: undefined,
            });
        } else {
            // box type
            onBatchChange({
                type: newType,
                polygonPoints: undefined,
            });
        }
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
                            aria-label={isExpanded ? 'Collapse entry' : 'Expand entry'}
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
                                aria-label="Remove coverage entry"
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
                    {/* Coverage Type Tabs */}
                    <Tabs value={entry.type} onValueChange={(value) => handleTypeChange(value as CoverageType)}>
                        <TabsList className="grid w-full grid-cols-3">
                            <TabsTrigger value="point">Point</TabsTrigger>
                            <TabsTrigger value="box">Bounding Box</TabsTrigger>
                            <TabsTrigger value="polygon">Polygon</TabsTrigger>
                        </TabsList>

                        <TabsContent value="point" className="mt-4">
                            <PointForm
                                entry={entry}
                                apiKey={apiKey}
                                onChange={onChange}
                                onBatchChange={onBatchChange}
                            />
                        </TabsContent>

                        <TabsContent value="box" className="mt-4">
                            <BoxForm
                                entry={entry}
                                apiKey={apiKey}
                                onChange={onChange}
                                onBatchChange={onBatchChange}
                            />
                        </TabsContent>

                        <TabsContent value="polygon" className="mt-4">
                            <PolygonForm
                                entry={entry}
                                apiKey={apiKey}
                                onChange={onChange}
                                onBatchChange={onBatchChange}
                            />
                        </TabsContent>
                    </Tabs>

                    {/* Temporal Coverage (Shared across all types) */}
                    <div className="space-y-4">
                        <h4 className="text-sm font-medium">Temporal Coverage</h4>
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
