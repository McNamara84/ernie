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
export function searchRorFunders(funders: RorFunder[], query: string, limit: number = 10): RorFunder[] {
    if (!query || query.trim().length < 2) {
        return [];
    }

    const searchTerm = query.toLowerCase().trim();

    // Split search term into words for multi-word matching
    const searchWords = searchTerm.split(/\s+/);

    const matches = funders
        .map((funder) => {
            let score = 0;
            const prefLabelLower = funder.prefLabel.toLowerCase();

            // Exact match in prefLabel gets highest score
            if (prefLabelLower === searchTerm) {
                score = 1000;
            }
            // Starts with search term gets high score
            else if (prefLabelLower.startsWith(searchTerm)) {
                score = 500;
            }
            // Contains all search words (for multi-word searches)
            else if (searchWords.every((word) => prefLabelLower.includes(word))) {
                score = 300;
            }
            // Contains search term anywhere
            else if (prefLabelLower.includes(searchTerm)) {
                score = 200;
            }
            // Check in otherLabel array
            else if (funder.otherLabel && Array.isArray(funder.otherLabel)) {
                for (const label of funder.otherLabel) {
                    const labelLower = label.toLowerCase();
                    if (labelLower === searchTerm) {
                        score = 800; // High score for exact match in alternate name
                        break;
                    } else if (labelLower.startsWith(searchTerm)) {
                        score = 400;
                        break;
                    } else if (searchWords.every((word) => labelLower.includes(word))) {
                        score = 250;
                        break;
                    } else if (labelLower.includes(searchTerm)) {
                        score = 150;
                        break;
                    }
                }
            }

            return { funder, score };
        })
        .filter((item) => item.score > 0)
        .sort((a, b) => b.score - a.score) // Sort by score descending
        .slice(0, limit)
        .map((item) => item.funder);

    return matches;
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

    return funders.find((funder) => {
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
        return (await response.json()) as RorFunder[];
    } catch (error) {
        console.error('Error loading ROR funders:', error);
        return [];
    }
}
