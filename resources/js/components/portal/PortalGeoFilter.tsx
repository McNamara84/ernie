import { Globe, MapPin, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type { GeoBounds } from '@/types/portal';

interface PortalGeoFilterProps {
    enabled: boolean;
    onToggle: (enabled: boolean) => void;
    bounds: GeoBounds | null;
    onBoundsChange: (bounds: GeoBounds | null) => void;
}

function parseCoord(value: string): number | null {
    const num = parseFloat(value);
    return isNaN(num) ? null : num;
}

export function PortalGeoFilter({ enabled, onToggle, bounds, onBoundsChange }: PortalGeoFilterProps) {
    const [north, setNorth] = useState('');
    const [south, setSouth] = useState('');
    const [east, setEast] = useState('');
    const [west, setWest] = useState('');
    const [error, setError] = useState<string | null>(null);

    // Sync local fields when bounds change from map viewport
    useEffect(() => {
        if (bounds) {
            setNorth(bounds.north.toFixed(4));
            setSouth(bounds.south.toFixed(4));
            setEast(bounds.east.toFixed(4));
            setWest(bounds.west.toFixed(4));
            setError(null);
        } else if (!enabled) {
            setNorth('');
            setSouth('');
            setEast('');
            setWest('');
            setError(null);
        }
    }, [bounds, enabled]);

    const handleApply = useCallback(() => {
        const n = parseCoord(north);
        const s = parseCoord(south);
        const e = parseCoord(east);
        const w = parseCoord(west);

        if (n === null || s === null || e === null || w === null) {
            setError('All four coordinates are required.');
            return;
        }

        if (n < -90 || n > 90 || s < -90 || s > 90) {
            setError('Latitude must be between -90 and 90.');
            return;
        }

        if (e < -180 || e > 180 || w < -180 || w > 180) {
            setError('Longitude must be between -180 and 180.');
            return;
        }

        if (n < s) {
            setError('North must be greater than or equal to South.');
            return;
        }

        setError(null);
        onBoundsChange({ north: n, south: s, east: e, west: w });
    }, [north, south, east, west, onBoundsChange]);

    const handleClear = useCallback(() => {
        onToggle(false);
        onBoundsChange(null);
    }, [onToggle, onBoundsChange]);

    const handleToggle = useCallback(
        (checked: boolean) => {
            onToggle(checked);
            if (!checked) {
                onBoundsChange(null);
            }
        },
        [onToggle, onBoundsChange],
    );

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">Geographic Filter</Label>
                {enabled && bounds && (
                    <Badge variant="secondary" className="text-xs">
                        <MapPin className="mr-1 h-3 w-3" />
                        Active
                    </Badge>
                )}
            </div>

            {/* Toggle */}
            <div className="flex items-center gap-3">
                <Switch checked={enabled} onCheckedChange={handleToggle} id="geo-filter-toggle" />
                <Label htmlFor="geo-filter-toggle" className="cursor-pointer text-sm font-normal">
                    Filter by map area
                </Label>
            </div>

            {enabled && (
                <p className="text-xs text-muted-foreground">
                    Zoom or pan the map to filter results by the visible area, or enter coordinates manually.
                </p>
            )}

            {/* Coordinate Fields */}
            {enabled && (
                <div className="space-y-2 rounded-md border p-3">
                    <div className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                        <Globe className="h-3 w-3" />
                        Bounding Box
                    </div>

                    {/* North */}
                    <div className="flex items-center justify-center gap-2">
                        <Label htmlFor="geo-north" className="w-10 text-xs text-muted-foreground">
                            N
                        </Label>
                        <Input
                            id="geo-north"
                            type="number"
                            step="0.0001"
                            min={-90}
                            max={90}
                            placeholder="52.5174"
                            value={north}
                            onChange={(e) => setNorth(e.target.value)}
                            className="h-8 text-xs"
                        />
                    </div>

                    {/* West / East */}
                    <div className="flex gap-2">
                        <div className="flex flex-1 items-center gap-2">
                            <Label htmlFor="geo-west" className="w-10 text-xs text-muted-foreground">
                                W
                            </Label>
                            <Input
                                id="geo-west"
                                type="number"
                                step="0.0001"
                                min={-180}
                                max={180}
                                placeholder="12.2371"
                                value={west}
                                onChange={(e) => setWest(e.target.value)}
                                className="h-8 text-xs"
                            />
                        </div>
                        <div className="flex flex-1 items-center gap-2">
                            <Label htmlFor="geo-east" className="w-10 text-xs text-muted-foreground">
                                E
                            </Label>
                            <Input
                                id="geo-east"
                                type="number"
                                step="0.0001"
                                min={-180}
                                max={180}
                                placeholder="13.7612"
                                value={east}
                                onChange={(e) => setEast(e.target.value)}
                                className="h-8 text-xs"
                            />
                        </div>
                    </div>

                    {/* South */}
                    <div className="flex items-center justify-center gap-2">
                        <Label htmlFor="geo-south" className="w-10 text-xs text-muted-foreground">
                            S
                        </Label>
                        <Input
                            id="geo-south"
                            type="number"
                            step="0.0001"
                            min={-90}
                            max={90}
                            placeholder="51.3497"
                            value={south}
                            onChange={(e) => setSouth(e.target.value)}
                            className="h-8 text-xs"
                        />
                    </div>

                    {/* Validation Error */}
                    {error && <p className="text-xs text-destructive">{error}</p>}

                    {/* Apply / Clear buttons */}
                    <div className="flex gap-2 pt-1">
                        <Button type="button" size="sm" variant="outline" className="h-7 flex-1 text-xs" onClick={handleApply}>
                            Apply
                        </Button>
                        <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={handleClear}>
                            <X className="mr-1 h-3 w-3" />
                            Clear
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
