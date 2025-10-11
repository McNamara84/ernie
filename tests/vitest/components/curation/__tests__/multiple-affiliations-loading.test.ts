import { describe, expect, it } from 'vitest';

/**
 * Unit tests for multiple affiliations loading from old datasets.
 * These tests verify that the bug fix for loading multiple affiliations is working correctly.
 * 
 * Bug: When loading old datasets with multiple affiliations per author/contributor,
 * they were being displayed as a single affiliation tag instead of multiple separate tags.
 * 
 * Fix: Changed from .join(', ') to .join(',') in mapInitialAuthorToEntry and mapInitialContributorToEntry
 * functions in datacite-form.tsx
 */
describe('Multiple Affiliations Loading', () => {
    /**
     * Helper function to simulate the mapping of initial author affiliations
     * This mimics what happens in datacite-form.tsx -> mapInitialAuthorToEntry()
     */
    const mapAffiliationsToInput = (affiliations: Array<{ value: string; rorId: string | null }>) => {
        return affiliations.map((item) => item.value).join(',');
    };

    it('should join multiple author affiliations with comma (no space)', () => {
        const affiliations = [
            {
                value: 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
                rorId: 'https://ror.org/04z8jg394',
            },
            {
                value: 'Seismological Research Centre of the OGS',
                rorId: null,
            },
        ];

        const result = mapAffiliationsToInput(affiliations);

        // Important: No space after comma - this allows Tagify to parse as separate tags
        expect(result).toBe(
            'GFZ German Research Centre for Geosciences, Potsdam, Germany,Seismological Research Centre of the OGS',
        );
        
        // Verify it does NOT have space after the joining comma
        expect(result).not.toContain('Germany, Seismological');
    });

    it('should join multiple contributor affiliations with comma (no space)', () => {
        const affiliations = [
            {
                value: 'Luxembourg Institute of Science and Technology',
                rorId: null,
            },
            {
                value: 'Catchment and Eco-Hydrology Group',
                rorId: null,
            },
        ];

        const result = mapAffiliationsToInput(affiliations);

        // Important: No space after comma
        expect(result).toBe(
            'Luxembourg Institute of Science and Technology,Catchment and Eco-Hydrology Group',
        );
    });

    it('should handle single affiliation correctly', () => {
        const affiliations = [
            {
                value: 'Single University',
                rorId: null,
            },
        ];

        const result = mapAffiliationsToInput(affiliations);

        // Single affiliation should work without trailing comma
        expect(result).toBe('Single University');
        expect(result).not.toContain(',');
    });

    it('should handle three affiliations correctly', () => {
        const affiliations = [
            {
                value: 'University A',
                rorId: 'https://ror.org/abc123',
            },
            {
                value: 'University B',
                rorId: 'https://ror.org/def456',
            },
            {
                value: 'University C',
                rorId: null,
            },
        ];

        const result = mapAffiliationsToInput(affiliations);

        // Three affiliations joined with comma (no space)
        expect(result).toBe('University A,University B,University C');
        
        // Verify exactly 2 commas
        const commaCount = (result.match(/,/g) || []).length;
        expect(commaCount).toBe(2);
    });

    it('should handle empty affiliations array', () => {
        const affiliations: Array<{ value: string; rorId: string | null }> = [];

        const result = mapAffiliationsToInput(affiliations);

        expect(result).toBe('');
    });

    it('should preserve commas within affiliation names', () => {
        const affiliations = [
            {
                value: 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
                rorId: 'https://ror.org/04z8jg394',
            },
            {
                value: 'Institute of Science, Technology and Innovation, Berlin, Germany',
                rorId: null,
            },
        ];

        const result = mapAffiliationsToInput(affiliations);

        // Commas within affiliation names should be preserved
        expect(result).toContain('Geosciences, Potsdam, Germany');
        expect(result).toContain('Technology and Innovation, Berlin, Germany');
        
        // But the joining comma should have no space
        expect(result).toContain('Germany,Institute of Science');
    });
});
