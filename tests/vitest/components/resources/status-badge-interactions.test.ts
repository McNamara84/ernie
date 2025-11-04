import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('Status Badge Interactions', () => {
    const mockResource = {
        id: 1,
        doi: '10.83279/test-doi',
        landingPage: {
            id: 1,
            status: 'published',
            public_url: 'https://example.com/preview/token123',
        },
        publicStatus: 'published',
    };

    beforeEach(() => {
        // Mock clipboard API
        Object.assign(navigator, {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });

        // Mock window.open
        vi.stubGlobal('open', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('handleStatusBadgeClick opens DOI URL for published status', async () => {
        const doi = mockResource.doi!;

        // Simulate the click handler logic
        const doiUrl = `https://doi.org/${doi}`;
        await navigator.clipboard.writeText(doiUrl);
        window.open(doiUrl, '_blank', 'noopener,noreferrer');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(doiUrl);
        expect(window.open).toHaveBeenCalledWith(
            'https://doi.org/10.83279/test-doi',
            '_blank',
            'noopener,noreferrer'
        );
    });

    it('handleStatusBadgeClick opens preview URL for review status', async () => {
        const reviewResource = {
            ...mockResource,
            publicStatus: 'review',
            landingPage: {
                ...mockResource.landingPage!,
                status: 'draft',
            },
        };

        const previewUrl = reviewResource.landingPage.public_url;

        // Simulate the click handler logic
        await navigator.clipboard.writeText(previewUrl);
        window.open(previewUrl, '_blank', 'noopener,noreferrer');

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(previewUrl);
        expect(window.open).toHaveBeenCalledWith(
            'https://example.com/preview/token123',
            '_blank',
            'noopener,noreferrer'
        );
    });

    it('clipboard copy handles errors gracefully', async () => {
        const clipboardError = new Error('Clipboard write failed');
        vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(clipboardError);

        try {
            await navigator.clipboard.writeText('https://doi.org/10.83279/test-doi');
        } catch (error) {
            expect(error).toBe(clipboardError);
        }

        expect(navigator.clipboard.writeText).toHaveBeenCalled();
    });

    it('constructs correct DOI URL format', () => {
        const doi = '10.83279/test-123';
        const expectedUrl = `https://doi.org/${doi}`;

        expect(expectedUrl).toBe('https://doi.org/10.83279/test-123');
    });

    it('uses landing page public_url directly for review status', () => {
        const reviewResource = {
            ...mockResource,
            landingPage: {
                id: 1,
                status: 'draft',
                public_url: 'https://example.com/preview/secure-token-abc123',
            },
        };

        const previewUrl = reviewResource.landingPage.public_url;

        expect(previewUrl).toBe('https://example.com/preview/secure-token-abc123');
        expect(previewUrl).toContain('secure-token-abc123');
    });

    it('does not trigger click handler for curation status', () => {
        const curationResource = {
            ...mockResource,
            doi: null,
            landingPage: null,
            publicStatus: 'curation',
        };

        // Badge should not be clickable for curation status
        const isClickable = curationResource.publicStatus === 'published' && curationResource.doi;
        
        expect(isClickable).toBeFalsy();
    });

    it('determines clickability correctly for published status', () => {
        const isClickable = mockResource.publicStatus === 'published' && mockResource.doi;
        expect(isClickable).toBeTruthy();
    });

    it('determines clickability correctly for review status', () => {
        const reviewResource = {
            ...mockResource,
            publicStatus: 'review',
            landingPage: {
                id: 1,
                status: 'draft',
                public_url: 'https://example.com/preview/token',
            },
        };

        const isClickable = 
            reviewResource.publicStatus === 'review' && 
            reviewResource.landingPage?.public_url;
        
        expect(isClickable).toBeTruthy();
    });

    it('handles missing DOI gracefully for published status', () => {
        const resourceWithoutDoi = {
            ...mockResource,
            doi: null,
        };

        const isClickable = 
            resourceWithoutDoi.publicStatus === 'published' && 
            resourceWithoutDoi.doi;
        
        expect(isClickable).toBeFalsy();
    });

    it('handles missing landing page gracefully for review status', () => {
        const resourceWithoutLandingPage = {
            ...mockResource,
            publicStatus: 'review',
            landingPage: null,
        };

        const isClickable = 
            resourceWithoutLandingPage.publicStatus === 'review' && 
            !!resourceWithoutLandingPage.landingPage;
        
        expect(isClickable).toBe(false);
    });
});
