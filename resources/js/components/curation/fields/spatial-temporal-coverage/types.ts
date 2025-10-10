/**
 * Types for Spatial and Temporal Coverage
 */

export interface SpatialTemporalCoverageEntry {
    id: string;

    // Spatial Information
    latMin: string; // Required, -90 to +90, max 6 decimals
    lonMin: string; // Required, -180 to +180, max 6 decimals
    latMax: string; // Optional (only for rectangle), -90 to +90, max 6 decimals
    lonMax: string; // Optional (only for rectangle), -180 to +180, max 6 decimals

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

export type DrawingMode = 'point' | 'rectangle' | null;
