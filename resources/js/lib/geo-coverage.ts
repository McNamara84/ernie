export const GLOBAL_COVERAGE_MESSAGE = 'This dataset has global spatial coverage.';

export const GLOBAL_COVERAGE_TOLERANCE = 0.000001;

interface BoundsLike {
    north: number | null;
    south: number | null;
    east: number | null;
    west: number | null;
}

function isNear(actual: number, expected: number): boolean {
    return Math.abs(actual - expected) <= GLOBAL_COVERAGE_TOLERANCE;
}

export function isGlobalCoverageBounds(bounds: BoundsLike, geoType?: string | null): boolean {
    if (geoType !== null && geoType !== undefined && geoType !== 'box') {
        return false;
    }

    if (bounds.west === null || bounds.east === null || bounds.south === null || bounds.north === null) {
        return false;
    }

    return (
        isNear(bounds.west, -180) &&
        isNear(bounds.east, 180) &&
        isNear(bounds.south, -90) &&
        isNear(bounds.north, 90)
    );
}
