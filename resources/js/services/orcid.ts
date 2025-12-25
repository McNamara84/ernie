/**
 * ORCID Service - Frontend API Client
 *
 * Provides methods to interact with the ORCID backend API
 */

/**
 * ORCID Person Data from API
 */
export interface OrcidPersonData {
    orcid: string;
    firstName: string;
    lastName: string;
    creditName: string | null;
    emails: string[];
    affiliations: OrcidAffiliation[];
    verifiedAt: string;
}

/**
 * ORCID Affiliation Data
 */
export interface OrcidAffiliation {
    type: 'employment' | 'education';
    name: string | null;
    role: string | null;
    department: string | null;
}

/**
 * ORCID Search Result
 */
export interface OrcidSearchResult {
    orcid: string;
    firstName: string;
    lastName: string;
    creditName: string | null;
    institutions: string[];
}

/**
 * ORCID Validation Result
 */
export interface OrcidValidationResult {
    valid: boolean;
    exists: boolean | null;
    message: string;
}

/**
 * API Response wrapper
 */
interface ApiResponse<T> {
    success: boolean;
    data?: T;
    message?: string;
    error?: string;
}

/**
 * ORCID Service Class
 */
export class OrcidService {
    /**
     * Fetch ORCID record data
     *
     * @param orcid The ORCID ID (format: XXXX-XXXX-XXXX-XXXX)
     * @returns Promise with ORCID person data or error
     */
    static async fetchOrcidRecord(orcid: string): Promise<{
        success: boolean;
        data?: OrcidPersonData;
        error?: string;
    }> {
        try {
            const response = await fetch(`/api/v1/orcid/${encodeURIComponent(orcid)}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                return {
                    success: false,
                    error: errorData?.message || `HTTP ${response.status}: Failed to fetch ORCID`,
                };
            }

            const result: ApiResponse<OrcidPersonData> = await response.json();

            if (!result.success || !result.data) {
                return {
                    success: false,
                    error: result.message || result.error || 'Failed to fetch ORCID data',
                };
            }

            return {
                success: true,
                data: result.data,
            };
        } catch (error) {
            console.error('ORCID fetch error:', error);
            return {
                success: false,
                error: 'Network error: Could not connect to ORCID service',
            };
        }
    }

    /**
     * Validate ORCID ID
     *
     * @param orcid The ORCID ID to validate
     * @returns Promise with validation result
     */
    static async validateOrcid(orcid: string): Promise<{
        success: boolean;
        data?: OrcidValidationResult;
        error?: string;
    }> {
        try {
            const response = await fetch(`/api/v1/orcid/validate/${encodeURIComponent(orcid)}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                return {
                    success: false,
                    error: `HTTP ${response.status}: Validation failed`,
                };
            }

            const result: OrcidValidationResult = await response.json();

            return {
                success: true,
                data: result,
            };
        } catch (error) {
            console.error('ORCID validation error:', error);
            return {
                success: false,
                error: 'Network error: Could not validate ORCID',
            };
        }
    }

    /**
     * Search for ORCID records by name
     *
     * @param query Search query (person name)
     * @param limit Number of results (default: 10, max: 50)
     * @returns Promise with search results
     */
    static async searchOrcid(
        query: string,
        limit: number = 10,
    ): Promise<{
        success: boolean;
        data?: {
            results: OrcidSearchResult[];
            total: number;
        };
        error?: string;
    }> {
        try {
            const params = new URLSearchParams({
                q: query,
                limit: Math.min(limit, 50).toString(),
            });

            const response = await fetch(`/api/v1/orcid/search?${params.toString()}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                return {
                    success: false,
                    error: errorData?.message || `HTTP ${response.status}: Search failed`,
                };
            }

            const result: ApiResponse<{
                results: OrcidSearchResult[];
                total: number;
            }> = await response.json();

            if (!result.success || !result.data) {
                return {
                    success: false,
                    error: result.message || result.error || 'Search failed',
                };
            }

            return {
                success: true,
                data: result.data,
            };
        } catch (error) {
            console.error('ORCID search error:', error);
            return {
                success: false,
                error: 'Network error: Could not search ORCID',
            };
        }
    }

    /**
     * Validate ORCID format (client-side)
     *
     * @param orcid The ORCID ID to validate
     * @returns True if format is valid
     */
    static isValidFormat(orcid: string): boolean {
        const pattern = /^(\d{4}-\d{4}-\d{4}-\d{3}[0-9X])$/;
        return pattern.test(orcid);
    }

    /**
     * Format ORCID for display
     *
     * @param orcid The ORCID ID
     * @returns Formatted ORCID with https URL
     */
    static formatOrcidUrl(orcid: string): string {
        return `https://orcid.org/${orcid}`;
    }
}
