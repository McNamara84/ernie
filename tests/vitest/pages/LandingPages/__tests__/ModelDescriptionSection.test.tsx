import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { ModelDescriptionSection } from '@/pages/LandingPages/components/ModelDescriptionSection';

describe('ModelDescriptionSection', () => {
    beforeEach(() => {
        vi.resetAllMocks();
    });

    it('returns null when no IsSupplementTo relation exists', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        const { container } = render(
            <ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('returns null when relatedIdentifiers is empty', () => {
        const { container } = render(
            <ModelDescriptionSection relatedIdentifiers={[]} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders the section title', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        expect(screen.getByRole('heading', { name: 'Model Description' })).toBeInTheDocument();
    });

    it('shows loading message while fetching citation', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        // Never resolve to keep loading state
        global.fetch = vi.fn().mockReturnValue(new Promise(() => {}));

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        expect(screen.getByText('Loading citation...')).toBeInTheDocument();
    });

    it('displays citation after successful fetch', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Smith, J. (2024). Test Model.' }),
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('Smith, J. (2024). Test Model.')).toBeInTheDocument();
        });
    });

    it('renders link with correct href to DOI', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/my-dataset',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            const link = screen.getByRole('link');
            expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/my-dataset');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });
    });

    it('shows related_title when citation fetch fails', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
                related_title: 'Fallback Model Title',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('Fallback Model Title')).toBeInTheDocument();
        });
    });

    it('does not fetch citation for non-DOI identifier types', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: 'http://example.com/model',
                identifier_type: 'URL',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn();

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        // Fetch should not be called for non-DOI types
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('only considers the first IsSupplementTo relation', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/first',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
            {
                id: 2,
                identifier: '10.5880/second',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'First Model Citation' }),
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        // Should only fetch the first IsSupplementTo
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('10.5880%2Ffirst'),
        );
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('encodes DOI identifier in the API URL', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/special/chars',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        expect(global.fetch).toHaveBeenCalledWith(
            '/api/datacite/citation/10.5880%2Fspecial%2Fchars',
        );
    });

    it('renders fallback link when fetch fails but related_title exists', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
                related_title: 'Model Title',
            },
        ];

        // Simulate fetch returning an error response (not a network error)
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            const link = screen.getByRole('link');
            expect(link).toHaveTextContent('Model Title');
            expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test');
        });
    });
});
