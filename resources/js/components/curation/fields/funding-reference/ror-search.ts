import type { RorFunder } from './types';

// Note: The ROR data file is loaded dynamically via fetch in the component
// since it's located outside the resources directory

/**
 * Search ROR funders by query string
 * Searches in prefLabel and otherLabel fields
 * 
 * @param funders - Array of ROR funders
 * @param query - Search term
 * @param limit - Maximum number of results (default 10)
 * @returns Array of matching ROR funders
 */
export function searchRorFunders(
    funders: RorFunder[],
    query: string,
    limit: number = 10
): RorFunder[] {
    if (!query || query.trim().length < 2) {
        return [];
    }

    const searchTerm = query.toLowerCase().trim();

    return funders
        .filter(funder => {
            // Search in prefLabel
            if (funder.prefLabel.toLowerCase().includes(searchTerm)) {
                return true;
            }

            // Search in otherLabel
            if (funder.otherLabel && Array.isArray(funder.otherLabel)) {
                return funder.otherLabel.some(label => 
                    label.toLowerCase().includes(searchTerm)
                );
            }

            return false;
        })
        .slice(0, limit);
}

/**
 * Get a ROR funder by exact ROR ID
 * 
 * @param funders - Array of ROR funders
 * @param rorId - ROR identifier (URL or just ID)
 * @returns ROR funder or undefined
 */
export function getFunderByRorId(funders: RorFunder[], rorId: string): RorFunder | undefined {
    if (!rorId) {
        return undefined;
    }

    // Normalize ROR ID (remove URL prefix if present)
    const normalizedId = rorId.replace(/^https?:\/\/ror\.org\//, '');

    return funders.find(funder => {
        const funderRorId = funder.rorId.replace(/^https?:\/\/ror\.org\//, '');
        return funderRorId === normalizedId;
    });
}

/**
 * Load ROR funders from the API endpoint
 * Uses the existing /api/v1/ror-affiliations endpoint
 * 
 * @returns Promise resolving to array of ROR funders
 */
export async function loadRorFunders(): Promise<RorFunder[]> {
    try {
        const response = await fetch('/api/v1/ror-affiliations');
        if (!response.ok) {
            throw new Error(`Failed to load ROR data: ${response.statusText}`);
        }
        const data = await response.json() as RorFunder[];
        console.log('Loaded ROR funders:', data.length);
        return data;
    } catch (error) {
        console.error('Error loading ROR funders:', error);
        return [];
    }
}

