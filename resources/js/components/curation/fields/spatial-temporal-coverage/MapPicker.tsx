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
    const isDrawingRectangle = useRef(false);
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
            } else if (drawingMode === 'rectangle') {
                if (!isDrawingRectangle.current) {
                    // Start drawing rectangle
                    rectangleStart.current = { lat, lng };
                    isDrawingRectangle.current = true;
                } else {
                    // Finish drawing rectangle
                    if (rectangleStart.current) {
                        const bounds: CoordinateBounds = {
                            north: Math.max(lat, rectangleStart.current.lat),
                            south: Math.min(lat, rectangleStart.current.lat),
                            east: Math.max(lng, rectangleStart.current.lng),
                            west: Math.min(lng, rectangleStart.current.lng),
                        };

                        // Clear marker
                        setMarker(null);

                        drawRectangleOnMap(bounds, map);
                        onRectangleSelected(bounds);

                        // Reset drawing state
                        isDrawingRectangle.current = false;
                        rectangleStart.current = null;
                        setDrawingMode(null);
                    }
                }
            }
        },
        [drawingMode, map, onPointSelected, onRectangleSelected, rectangle, drawRectangleOnMap],
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
                            isDrawingRectangle.current = false;
                            rectangleStart.current = null;
                        }
                    }}
                >
                    ▭ Rectangle
                </Button>
                {drawingMode && (
                    <span className="text-xs text-muted-foreground self-center">
                        {drawingMode === 'point'
                            ? 'Click on the map to place a marker'
                            : isDrawingRectangle.current
                              ? 'Click again to finish rectangle'
                              : 'Click to start drawing rectangle'}
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
