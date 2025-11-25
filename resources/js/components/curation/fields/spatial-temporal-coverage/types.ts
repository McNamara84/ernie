/**
 * Types for Spatial and Temporal Coverage
 */

export type CoverageType = 'point' | 'box' | 'polygon';

export interface PolygonPoint {
    lat: number;
    lon: number;
}

export interface SpatialTemporalCoverageEntry {
    id: string;

    // Coverage Type
    type: CoverageType; // Required, determines which spatial data is used

    // Spatial Information (Point/Box)
    latMin: string; // Required for point/box, -90 to +90, max 6 decimals
    lonMin: string; // Required for point/box, -180 to +180, max 6 decimals
    latMax: string; // Optional (only for box), -90 to +90, max 6 decimals
    lonMax: string; // Optional (only for box), -180 to +180, max 6 decimals

    // Spatial Information (Polygon)
    polygonPoints?: PolygonPoint[]; // Optional (only for polygon), min 3 points

    // Temporal Information
    startDate: string; // Required, format: YYYY-MM-DD
    endDate: string; // Required, format: YYYY-MM-DD
    startTime: string; // Optional, format: HH:MM
    endTime: string; // Optional, format: HH:MM
    timezone: string; // Required, e.g., "Europe/Berlin"

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

export type DrawingMode = 'point' | 'rectangle' | 'polygon' | null;
