import {
    AdvancedMarker,
    APIProvider,
    Map,
    MapMouseEvent,
    useMap,
} from '@vis.gl/react-google-maps';
import { Maximize2 } from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import type { CoordinateBounds, Coordinates } from './types';

interface MapPickerProps {
    apiKey: string;
    latMin: string;
    lonMin: string;
    latMax: string;
    lonMax: string;
    onPointSelected: (lat: number, lng: number) => void;
    onRectangleSelected: (bounds: CoordinateBounds) => void;
}

/**
 * MapPickerContent - Internal component that uses the Map context
 */
function MapPickerContent({
    latMin,
    lonMin,
    latMax,
    lonMax,
    onPointSelected,
    onRectangleSelected,
}: Omit<MapPickerProps, 'apiKey'>) {
    const map = useMap();
    const [drawingMode, setDrawingMode] = useState<'point' | 'rectangle' | null>(null);
    const [marker, setMarker] = useState<Coordinates | null>(null);
    const [rectangle, setRectangle] = useState<google.maps.Rectangle | null>(null);
    const rectangleRef = useRef<google.maps.Rectangle | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [isDragging, setIsDragging] = useState(false);
    // Store preview rectangle only in ref, not in state to avoid re-renders
    const previewRectangleRef = useRef<google.maps.Rectangle | null>(null);
    const rectangleStart = useRef<Coordinates | null>(null);

    // Sync rectangle state with ref
    useEffect(() => {
        rectangleRef.current = rectangle;
    }, [rectangle]);

    const drawRectangleOnMap = useCallback(
        (bounds: CoordinateBounds, mapInstance: google.maps.Map) => {
            // Remove existing rectangle using ref to avoid dependency cycle
            if (rectangleRef.current) {
                rectangleRef.current.setMap(null);
            }

            // Also ensure preview rectangle is removed
            if (previewRectangleRef.current) {
                previewRectangleRef.current.setMap(null);
                previewRectangleRef.current = null;
            }

            const newRectangle = new google.maps.Rectangle({
                bounds: {
                    north: bounds.north,
                    south: bounds.south,
                    east: bounds.east,
                    west: bounds.west,
                },
                editable: false,
                draggable: false,
                strokeColor: '#FF0000',
                strokeOpacity: 0.8,
                strokeWeight: 2,
                fillColor: '#FF0000',
                fillOpacity: 0.15,
            });

            newRectangle.setMap(mapInstance);
            setRectangle(newRectangle);

            // Fit bounds to rectangle
            mapInstance.fitBounds(newRectangle.getBounds()!);
        },
        [], // No dependencies - use rectangleRef instead to avoid infinite loop
    );

    /**
     * Handler for mouse down event - starts rectangle drawing
     */
    const handleMouseDown = useCallback(
        (event: google.maps.MapMouseEvent) => {
            if (drawingMode !== 'rectangle' || !event.latLng || !map) return;

            const lat = event.latLng.lat();
            const lng = event.latLng.lng();

            rectangleStart.current = { lat, lng };
            setIsDragging(true);

            // Disable map dragging during rectangle drawing
            map.setOptions({ draggable: false });

            // Clear any existing marker
            setMarker(null);

            // Clear any existing preview rectangle synchronously
            if (previewRectangleRef.current) {
                previewRectangleRef.current.setMap(null);
                previewRectangleRef.current = null; // Set ref to null immediately
            }
        },
        [drawingMode, map],
    );

    /**
     * Handler for mouse move event - updates preview rectangle during drag
     */
    const handleMouseMove = useCallback(
        (event: google.maps.MapMouseEvent) => {
            if (!isDragging || !rectangleStart.current || !event.latLng || !map) return;

            const lat = event.latLng.lat();
            const lng = event.latLng.lng();

            const bounds = {
                north: Math.max(lat, rectangleStart.current.lat),
                south: Math.min(lat, rectangleStart.current.lat),
                east: Math.max(lng, rectangleStart.current.lng),
                west: Math.min(lng, rectangleStart.current.lng),
            };

            // Update existing preview rectangle or create new one
            if (previewRectangleRef.current) {
                // Update bounds of existing rectangle
                previewRectangleRef.current.setBounds(bounds);
            } else {
                // Create new preview rectangle only if it doesn't exist
                const newPreviewRectangle = new google.maps.Rectangle({
                    bounds,
                    editable: false,
                    draggable: false,
                    strokeColor: '#3B82F6',
                    strokeOpacity: 0.6,
                    strokeWeight: 2,
                    fillColor: '#3B82F6',
                    fillOpacity: 0.1,
                    clickable: false,
                });

                newPreviewRectangle.setMap(map);
                // Set ref immediately to prevent race condition
                previewRectangleRef.current = newPreviewRectangle;
            }
        },
        [isDragging, map],
    );

    /**
     * Handler for mouse up event - finalizes rectangle drawing
     */
    const handleMouseUp = useCallback(
        (event: google.maps.MapMouseEvent) => {
            if (!isDragging || !rectangleStart.current || !event.latLng || !map) return;

            const lat = event.latLng.lat();
            const lng = event.latLng.lng();

            const bounds: CoordinateBounds = {
                north: Math.max(lat, rectangleStart.current.lat),
                south: Math.min(lat, rectangleStart.current.lat),
                east: Math.max(lng, rectangleStart.current.lng),
                west: Math.min(lng, rectangleStart.current.lng),
            };

            // Remove preview rectangle synchronously
            if (previewRectangleRef.current) {
                previewRectangleRef.current.setMap(null);
                previewRectangleRef.current = null; // Set ref to null immediately
            }

            // Re-enable map dragging
            map.setOptions({ draggable: true });

            // Reset drawing state immediately to prevent more mousemove events
            setIsDragging(false);
            rectangleStart.current = null;
            setDrawingMode(null);

            // Draw final rectangle after cleanup
            drawRectangleOnMap(bounds, map);
            onRectangleSelected(bounds);
        },
        [isDragging, map, drawRectangleOnMap, onRectangleSelected],
    );

    // Initialize marker/rectangle from props
    useEffect(() => {
        if (!map) return;

        const lat = parseFloat(latMin);
        const lon = parseFloat(lonMin);

        if (!isNaN(lat) && !isNaN(lon)) {
            // Check if we have a rectangle (max values exist)
            const latMaxNum = parseFloat(latMax);
            const lonMaxNum = parseFloat(lonMax);

            if (!isNaN(latMaxNum) && !isNaN(lonMaxNum)) {
                // Draw rectangle
                setMarker(null);
                drawRectangleOnMap(
                    {
                        south: Math.min(lat, latMaxNum),
                        north: Math.max(lat, latMaxNum),
                        west: Math.min(lon, lonMaxNum),
                        east: Math.max(lon, lonMaxNum),
                    },
                    map,
                );
            } else {
                // Draw point marker
                setMarker({ lat, lng: lon });
                map.panTo({ lat, lng: lon });
            }
        }
    }, [latMin, lonMin, latMax, lonMax, map, drawRectangleOnMap]);

    // Register mouse event listeners for drag & drop rectangle drawing
    useEffect(() => {
        if (!map || drawingMode !== 'rectangle') return;

        // Set crosshair cursor during rectangle mode
        map.setOptions({ draggableCursor: 'crosshair' });

        const listeners = [
            map.addListener('mousedown', handleMouseDown),
            map.addListener('mousemove', handleMouseMove),
            map.addListener('mouseup', handleMouseUp),
        ];

        return () => {
            // Remove listeners and reset cursor
            listeners.forEach((listener) => listener.remove());
            map.setOptions({ draggableCursor: undefined });
        };
    }, [map, drawingMode, handleMouseDown, handleMouseMove, handleMouseUp]);

    // Cleanup preview rectangle on mode change or unmount
    useEffect(() => {
        return () => {
            if (previewRectangleRef.current) {
                previewRectangleRef.current.setMap(null);
                previewRectangleRef.current = null; // Set ref to null immediately
            }
            // Re-enable map dragging on cleanup
            if (map && isDragging) {
                map.setOptions({ draggable: true });
            }
        };
    }, [drawingMode, map, isDragging]);

    const handleMapClick = useCallback(
        (event: MapMouseEvent) => {
            if (!map || !event.detail.latLng) return;

            const { lat, lng } = event.detail.latLng;

            if (drawingMode === 'point') {
                // Clear any existing rectangle
                if (rectangle) {
                    rectangle.setMap(null);
                    setRectangle(null);
                }

                setMarker({ lat, lng });
                onPointSelected(lat, lng);
                setDrawingMode(null);
            }
            // Rectangle drawing is now handled by drag & drop (mousedown/mousemove/mouseup)
        },
        [drawingMode, map, onPointSelected, rectangle],
    );

    const handleSearch = useCallback(async () => {
        if (!map || !searchQuery.trim()) return;

        const geocoder = new google.maps.Geocoder();
        try {
            const result = await geocoder.geocode({ address: searchQuery });
            if (result.results && result.results.length > 0) {
                const location = result.results[0].geometry.location;
                map.panTo(location);
                map.setZoom(12);

                // Optionally set a marker at the searched location
                const lat = location.lat();
                const lng = location.lng();
                setMarker({ lat, lng });
                onPointSelected(lat, lng);
            }
        } catch (error) {
            console.error('Geocoding error:', error);
        }
    }, [map, searchQuery, onPointSelected]);

    const handleKeyPress = useCallback(
        (event: React.KeyboardEvent<HTMLInputElement>) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleSearch();
            }
        },
        [handleSearch],
    );

    return (
        <div className="space-y-3">
            {/* Search Bar */}
            <div className="flex gap-2">
                <div className="flex-1">
                    <Input
                        type="text"
                        placeholder="Search for a location..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        onKeyPress={handleKeyPress}
                    />
                </div>
                <Button type="button" onClick={handleSearch} variant="outline">
                    Search
                </Button>
            </div>

            {/* Drawing Tools */}
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant={drawingMode === 'point' ? 'default' : 'outline'}
                    size="sm"
                    onClick={() =>
                        setDrawingMode(drawingMode === 'point' ? null : 'point')
                    }
                >
                    📍 Point
                </Button>
                <Button
                    type="button"
                    variant={drawingMode === 'rectangle' ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => {
                        const newMode = drawingMode === 'rectangle' ? null : 'rectangle';
                        setDrawingMode(newMode);
                        if (newMode === null) {
                            // Re-enable map dragging if it was disabled during rectangle drawing
                            const wasDragging = isDragging;
                            
                            // Reset drag state when exiting rectangle mode
                            setIsDragging(false);
                            rectangleStart.current = null;
                            
                            if (map && wasDragging) {
                                map.setOptions({ draggable: true });
                            }
                        }
                    }}
                >
                    ▭ Rectangle
                </Button>
                {drawingMode && (
                    <span className="text-xs text-muted-foreground self-center">
                        {drawingMode === 'point'
                            ? 'Click on the map to place a marker'
                            : isDragging
                              ? 'Release mouse to finish rectangle'
                              : 'Click and drag to draw rectangle'}
                    </span>
                )}
            </div>

            {/* Map Container */}
            <div className="relative h-64 rounded-lg overflow-hidden border">
                <Map
                    mapId="spatial-coverage-map"
                    defaultCenter={{ lat: 48.137154, lng: 11.576124 }} // Munich as default
                    defaultZoom={10}
                    gestureHandling="greedy"
                    disableDefaultUI={false}
                    onClick={handleMapClick}
                    mapTypeId="satellite"
                >
                    {marker && <AdvancedMarker position={marker} />}
                </Map>
            </div>

            <p className="text-xs text-muted-foreground">
                Use the drawing tools to select a point or rectangle on the map. The
                coordinates will be automatically filled in the form.
            </p>
        </div>
    );
}

/**
 * MapPicker Component
 * Provides interactive Google Maps for selecting spatial coverage
 */
export default function MapPicker(props: MapPickerProps) {
    const [isFullscreen, setIsFullscreen] = useState(false);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">Map Picker</Label>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setIsFullscreen(true)}
                >
                    <Maximize2 className="h-4 w-4 mr-2" />
                    Fullscreen
                </Button>
            </div>

            <APIProvider apiKey={props.apiKey}>
                <MapPickerContent {...props} />
            </APIProvider>

            {/* Fullscreen Dialog */}
            <Dialog open={isFullscreen} onOpenChange={setIsFullscreen}>
                <DialogContent className="max-w-5xl h-[80vh]">
                    <DialogHeader>
                        <DialogTitle>Map Picker - Fullscreen</DialogTitle>
                        <DialogDescription>
                            Select a point or rectangle to define the spatial coverage.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex-1 h-full">
                        <APIProvider apiKey={props.apiKey}>
                            <MapPickerContent {...props} />
                        </APIProvider>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
