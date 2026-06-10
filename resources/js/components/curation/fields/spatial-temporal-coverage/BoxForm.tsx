import { Globe2 } from 'lucide-react';

import { Button } from '@/components/ui/button';

import CoordinateInputs from './CoordinateInputs';
import MapPicker from './MapPicker';
import type { CoordinateBounds, SpatialTemporalCoverageEntry } from './types';

interface BoxFormProps {
    entry: SpatialTemporalCoverageEntry;
    apiKey: string;
    onChange: (field: keyof SpatialTemporalCoverageEntry, value: string) => void;
    onBatchChange: (updates: Partial<SpatialTemporalCoverageEntry>) => void;
}

export default function BoxForm({ entry, apiKey, onChange, onBatchChange }: BoxFormProps) {
    const handleGlobalCoverageClick = () => {
        onBatchChange({
            latMin: '-90.000000',
            latMax: '90.000000',
            lonMin: '-180.000000',
            lonMax: '180.000000',
        });
    };

    const handleRectangleSelected = (bounds: CoordinateBounds) => {
        const latMinStr = bounds.south.toFixed(6);
        const latMaxStr = bounds.north.toFixed(6);
        const lonMinStr = bounds.west.toFixed(6);
        const lonMaxStr = bounds.east.toFixed(6);

        console.log('Rectangle selected:', {
            latMin: latMinStr,
            latMax: latMaxStr,
            lonMin: lonMinStr,
            lonMax: lonMaxStr,
        });

        onBatchChange({
            latMin: latMinStr,
            latMax: latMaxStr,
            lonMin: lonMinStr,
            lonMax: lonMaxStr,
        });
    };

    const handleCoordinateChange = (field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax', value: string) => {
        onChange(field, value);
    };

    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Left Column: Map */}
            <div className="space-y-4">
                <MapPicker
                    apiKey={apiKey}
                    latMin={entry.latMin}
                    lonMin={entry.lonMin}
                    latMax={entry.latMax}
                    lonMax={entry.lonMax}
                    onPointSelected={() => {}}
                    onRectangleSelected={handleRectangleSelected}
                    allowedModes={['rectangle']}
                />
            </div>

            {/* Right Column: Coordinates */}
            <div className="space-y-4">
                <div className="flex justify-end">
                    <Button type="button" variant="outline" size="sm" onClick={handleGlobalCoverageClick}>
                        <Globe2 className="mr-2 h-4 w-4" />
                        Global
                    </Button>
                </div>
                <CoordinateInputs
                    latMin={entry.latMin}
                    lonMin={entry.lonMin}
                    latMax={entry.latMax}
                    lonMax={entry.lonMax}
                    onChange={handleCoordinateChange}
                    showLabels={true}
                    coordinateOrder="lat-lon"
                />
            </div>
        </div>
    );
}
