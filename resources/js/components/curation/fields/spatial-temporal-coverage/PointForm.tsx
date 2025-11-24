import React from 'react';

import CoordinateInputs from './CoordinateInputs';
import MapPicker from './MapPicker';
import type { SpatialTemporalCoverageEntry } from './types';

interface PointFormProps {
    entry: SpatialTemporalCoverageEntry;
    apiKey: string;
    onChange: (field: keyof SpatialTemporalCoverageEntry, value: string) => void;
    onBatchChange: (updates: Partial<SpatialTemporalCoverageEntry>) => void;
}

export default function PointForm({
    entry,
    apiKey,
    onChange,
    onBatchChange,
}: PointFormProps) {
    const handlePointSelected = (lat: number, lng: number) => {
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

    const handleCoordinateChange = (
        field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax',
        value: string,
    ) => {
        onChange(field, value);
    };

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Left Column: Map */}
            <div className="space-y-4">
                <MapPicker
                    apiKey={apiKey}
                    latMin={entry.latMin}
                    lonMin={entry.lonMin}
                    latMax=""
                    lonMax=""
                    onPointSelected={handlePointSelected}
                    onRectangleSelected={() => {}}
                />
            </div>

            {/* Right Column: Coordinates */}
            <div className="space-y-4">
                <CoordinateInputs
                    latMin={entry.latMin}
                    lonMin={entry.lonMin}
                    latMax=""
                    lonMax=""
                    onChange={handleCoordinateChange}
                    showLabels={true}
                />
            </div>
        </div>
    );
}
