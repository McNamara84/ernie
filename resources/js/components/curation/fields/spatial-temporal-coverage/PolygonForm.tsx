import React from 'react';

import type { SpatialTemporalCoverageEntry } from './types';

interface PolygonFormProps {
    entry: SpatialTemporalCoverageEntry;
    apiKey: string;
    onChange: (field: keyof SpatialTemporalCoverageEntry, value: string) => void;
    onBatchChange: (updates: Partial<SpatialTemporalCoverageEntry>) => void;
}

export default function PolygonForm({
    entry,
    apiKey,
    onChange,
    onBatchChange,
}: PolygonFormProps) {
    return (
        <div className="grid grid-cols-1 gap-6">
            <div className="p-8 border-2 border-dashed rounded-lg text-center text-muted-foreground">
                <p className="text-lg font-semibold mb-2">Polygon Drawing</p>
                <p className="text-sm">
                    Polygon drawing functionality will be implemented in Phase 7.
                </p>
                <p className="text-sm mt-2">
                    You can already select this type, but drawing capabilities are coming soon.
                </p>
            </div>
        </div>
    );
}
