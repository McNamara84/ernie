import { APIProvider, Map, useMap } from '@vis.gl/react-google-maps';
import { MapPin, Maximize2, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

import type { PolygonPoint, SpatialTemporalCoverageEntry } from './types';

interface LineFormProps {
    entry: SpatialTemporalCoverageEntry;
    apiKey: string;
    onChange: (field: keyof SpatialTemporalCoverageEntry, value: string) => void;
    onBatchChange: (updates: Partial<SpatialTemporalCoverageEntry>) => void;
}

/**
 * LineMapContent - Internal component that uses the Map context
 * Renders a polyline (open path) instead of a closed polygon
 */
function LineMapContent({ points, onPointsChange }: { points: PolygonPoint[]; onPointsChange: (points: PolygonPoint[]) => void }) {
    const map = useMap();
    const [isDrawing, setIsDrawing] = useState(false);
    const polylineRef = useRef<google.maps.Polyline | null>(null);
    const markersRef = useRef<google.maps.Marker[]>([]);

    // Draw polyline on map
    const drawPolyline = useCallback(
        (linePoints: PolygonPoint[], mapInstance: google.maps.Map) => {
            // Remove existing polyline
            if (polylineRef.current) {
                polylineRef.current.setMap(null);
            }

            // Remove existing markers
            markersRef.current.forEach((marker) => marker.setMap(null));
            markersRef.current = [];

            if (linePoints.length === 0) return;

            // Create polyline (open path, not closed)
            const polyline = new google.maps.Polyline({
                path: linePoints.map((p) => ({ lat: p.lat, lng: p.lon })),
                strokeColor: '#059669',
                strokeOpacity: 0.9,
                strokeWeight: 3,
                editable: true,
                draggable: false,
            });

            polyline.setMap(mapInstance);
            polylineRef.current = polyline;

            // Add markers for each vertex
            linePoints.forEach((point, index) => {
                const marker = new google.maps.Marker({
                    position: { lat: point.lat, lng: point.lon },
                    map: mapInstance,
                    draggable: true,
                    label: {
                        text: `${index + 1}`,
                        color: 'white',
                        fontSize: '12px',
                        fontWeight: 'bold',
                    },
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 8,
                        fillColor: '#059669',
                        fillOpacity: 1,
                        strokeColor: 'white',
                        strokeWeight: 2,
                    },
                });

                // Handle marker drag
                marker.addListener('dragend', () => {
                    const position = marker.getPosition();
                    if (position) {
                        const newPoints = [...linePoints];
                        newPoints[index] = {
                            lat: Number(position.lat().toFixed(6)),
                            lon: Number(position.lng().toFixed(6)),
                        };
                        onPointsChange(newPoints);
                    }
                });

                markersRef.current.push(marker);
            });

            // Handle polyline path changes (vertex dragging)
            google.maps.event.addListener(polyline.getPath(), 'set_at', () => {
                const path = polyline.getPath();
                const newPoints: PolygonPoint[] = [];
                for (let i = 0; i < path.getLength(); i++) {
                    const point = path.getAt(i);
                    newPoints.push({
                        lat: Number(point.lat().toFixed(6)),
                        lon: Number(point.lng().toFixed(6)),
                    });
                }
                onPointsChange(newPoints);
            });

            // Fit bounds if we have points
            if (linePoints.length > 0) {
                const bounds = new google.maps.LatLngBounds();
                linePoints.forEach((p) => bounds.extend({ lat: p.lat, lng: p.lon }));
                mapInstance.fitBounds(bounds);
            }
        },
        [onPointsChange],
    );

    // Redraw polyline when points change
    useEffect(() => {
        if (map && points.length > 0) {
            drawPolyline(points, map);
        }

        return () => {
            if (polylineRef.current) {
                polylineRef.current.setMap(null);
            }
            markersRef.current.forEach((marker) => marker.setMap(null));
            markersRef.current = [];
        };
    }, [points, map, drawPolyline]);

    // Handle map clicks to add points
    const handleMapClick = useCallback(
        (e: google.maps.MapMouseEvent) => {
            if (!isDrawing || !e.latLng) return;

            const lat = Number(e.latLng.lat().toFixed(6));
            const lon = Number(e.latLng.lng().toFixed(6));

            onPointsChange([...points, { lat, lon }]);
        },
        [isDrawing, points, onPointsChange],
    );

    useEffect(() => {
        if (!map) return;

        const listener = map.addListener('click', handleMapClick);

        return () => {
            google.maps.event.removeListener(listener);
        };
    }, [map, handleMapClick]);

    return (
        <div className="space-y-4">
            {/* Drawing Controls */}
            <div className="flex items-center gap-4">
                <Button type="button" variant={isDrawing ? 'default' : 'outline'} size="sm" onClick={() => setIsDrawing(!isDrawing)}>
                    {isDrawing ? '✓ Drawing Mode Active' : '🖊️ Start Drawing'}
                </Button>
                {points.length > 0 && (
                    <Button type="button" variant="outline" size="sm" onClick={() => onPointsChange([])}>
                        <Trash2 className="mr-2 h-4 w-4" />
                        Clear Line
                    </Button>
                )}
                {isDrawing && <span className="text-xs text-muted-foreground">Click on the map to add waypoints (minimum 2 required)</span>}
            </div>

            {/* Map */}
            <div style={{ width: '100%', height: '400px' }}>
                <Map
                    mapId="line-coverage-picker"
                    defaultCenter={{ lat: 20, lng: 0 }}
                    defaultZoom={2}
                    gestureHandling="greedy"
                    disableDefaultUI={false}
                    zoomControl={true}
                    mapTypeControl={false}
                    streetViewControl={false}
                    fullscreenControl={false}
                    style={{ width: '100%', height: '100%' }}
                />
            </div>
        </div>
    );
}

export default function LineForm({ entry, apiKey, onBatchChange }: LineFormProps) {
    const [isFullscreen, setIsFullscreen] = useState(false);
    const points = entry.polygonPoints || [];

    const handlePointsChange = useCallback(
        (newPoints: PolygonPoint[]) => {
            onBatchChange({ polygonPoints: newPoints });
        },
        [onBatchChange],
    );

    const handlePointChange = (index: number, field: 'lat' | 'lon', value: string) => {
        const numValue = parseFloat(value);
        if (isNaN(numValue)) return;

        const newPoints = [...points];
        newPoints[index] = { ...newPoints[index], [field]: numValue };
        handlePointsChange(newPoints);
    };

    const handleAddPoint = () => {
        handlePointsChange([...points, { lat: 0, lon: 0 }]);
    };

    const handleRemovePoint = (index: number) => {
        const newPoints = points.filter((_, i) => i !== index);
        handlePointsChange(newPoints);
    };

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Left Column: Map */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <Label className="text-sm font-medium">Line Map</Label>
                        <Button type="button" variant="outline" size="sm" onClick={() => setIsFullscreen(true)}>
                            <Maximize2 className="mr-2 h-4 w-4" />
                            Fullscreen
                        </Button>
                    </div>
                    <APIProvider apiKey={apiKey}>
                        <LineMapContent points={points} onPointsChange={handlePointsChange} />
                    </APIProvider>
                </div>

                {/* Right Column: Coordinates Table */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <Label className="text-sm font-medium">Line Points ({points.length})</Label>
                        <Button type="button" variant="outline" size="sm" onClick={handleAddPoint}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Point
                        </Button>
                    </div>

                    {points.length === 0 ? (
                        <EmptyState
                            icon={<MapPin className="h-6 w-6" />}
                            title="No points yet"
                            description='Click "Start Drawing" and click on the map to add waypoints, or use "Add Point" button to enter coordinates manually.'
                            variant="compact"
                            data-testid="line-points-empty-state"
                        />
                    ) : (
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">#</TableHead>
                                        <TableHead>Latitude</TableHead>
                                        <TableHead>Longitude</TableHead>
                                        <TableHead className="w-12"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {points.map((point, idx) => (
                                        <TableRow key={idx}>
                                            <TableCell className="font-medium">{idx + 1}</TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="0.000001"
                                                    value={point.lat}
                                                    onChange={(e) => handlePointChange(idx, 'lat', e.target.value)}
                                                    className="h-8"
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="0.000001"
                                                    value={point.lon}
                                                    onChange={(e) => handlePointChange(idx, 'lon', e.target.value)}
                                                    className="h-8"
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleRemovePoint(idx)}
                                                    className="h-8 w-8 p-0"
                                                >
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}

                    {points.length > 0 && points.length < 2 && (
                        <p className="text-sm text-amber-600">⚠️ Minimum 2 points required for a valid line. Currently: {points.length}</p>
                    )}
                </div>
            </div>

            {/* Fullscreen Dialog */}
            <Dialog open={isFullscreen} onOpenChange={setIsFullscreen}>
                <DialogContent className="h-[80vh] max-w-5xl">
                    <DialogHeader>
                        <DialogTitle>Line Map - Fullscreen</DialogTitle>
                        <DialogDescription>Click on the map to add waypoints. Drag vertices to adjust the line shape.</DialogDescription>
                    </DialogHeader>
                    <div className="h-full flex-1">
                        <APIProvider apiKey={apiKey}>
                            <LineMapContent points={points} onPointsChange={handlePointsChange} />
                        </APIProvider>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
