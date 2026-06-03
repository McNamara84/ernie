export const GLOBAL_COVERAGE_MESSAGE = 'This dataset has global spatial coverage.';

export const DEFAULT_GLOBAL_COVERAGE_TOLERANCE = 0.000001;

interface BoundsLike {
    north: number | null;
    south: number | null;
    east: number | null;
    west: number | null;
}

export function getGlobalCoverageTolerance(): number {
    const configuredTolerance = import.meta.env.VITE_GEO_GLOBAL_COVERAGE_TOLERANCE;

    if (typeof configuredTolerance !== 'string' || configuredTolerance.trim() === '') {
        return DEFAULT_GLOBAL_COVERAGE_TOLERANCE;
    }

    const tolerance = Number(configuredTolerance);

    if (!Number.isFinite(tolerance) || tolerance < 0) {
        return DEFAULT_GLOBAL_COVERAGE_TOLERANCE;
    }

    return tolerance;
}

function isNear(actual: number, expected: number, tolerance: number): boolean {
    return Math.abs(actual - expected) <= tolerance;
}

export function isGlobalCoverageBounds(bounds: BoundsLike, geoType?: string | null): boolean {
    if (geoType !== null && geoType !== undefined && geoType !== 'box') {
        return false;
    }

    if (bounds.west === null || bounds.east === null || bounds.south === null || bounds.north === null) {
        return false;
    }

    const tolerance = getGlobalCoverageTolerance();

    return isNear(bounds.west, -180, tolerance)
        && isNear(bounds.east, 180, tolerance)
        && isNear(bounds.south, -90, tolerance)
        && isNear(bounds.north, 90, tolerance);
}
