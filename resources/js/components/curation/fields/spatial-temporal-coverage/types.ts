/**
 * Types for Spatial and Temporal Coverage
 */

export type CoverageType = 'point' | 'box' | 'polygon' | 'line';

export interface PolygonPoint {
    lat: number;
    lon: number;
}

export interface SpatialTemporalCoverageEntry {
    id: string;

    // Coverage Type
    type: CoverageType; // Required, determines which spatial data is used

    // Spatial Information (Point/Box)
    latMin: string; // Optional; required with lonMin when a point/box is entered
    lonMin: string; // Optional; required with latMin when a point/box is entered
    latMax: string; // Optional (only for box), -90 to +90, max 6 decimals
    lonMax: string; // Optional (only for box), -180 to +180, max 6 decimals

    // Spatial Information (Polygon/Line)
    polygonPoints?: PolygonPoint[]; // Optional (for polygon min 3, for line min 2)

    // Temporal Information
    startDate: string; // Optional, format: YYYY-MM-DD
    endDate: string; // Optional, format: YYYY-MM-DD
    startTime: string; // Optional, format: HH:MM
    endTime: string; // Optional, format: HH:MM
    timezone: string; // Optional IANA zone, UTC, or numeric offset

    // Description
    description: string; // Optional
}

export interface Coordinates {
    lat: number;
    lng: number;
}

export interface CoordinateBounds {
    north: number;
    south: number;
    east: number;
    west: number;
}

export type DrawingMode = 'point' | 'rectangle' | 'polygon' | 'line' | null;
