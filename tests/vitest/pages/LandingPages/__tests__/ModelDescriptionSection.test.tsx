import { render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach,describe, expect, it, vi } from 'vitest';

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

    it('shows loading skeleton while fetching citation', () => {
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

        // Loading state now uses Skeleton components with aria-busy
        const busyEl = document.querySelector('[aria-busy="true"]');
        expect(busyEl).toBeInTheDocument();
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
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('encodes DOI identifier in the API URL', async () => {
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

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/datacite/citation?doi=10.5880%2Fspecial%2Fchars',
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            );
        });
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

    it('returns null when identifier is empty (unresolvable URL)', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn();

        const { container } = render(
            <ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />,
        );

        expect(container.firstChild).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('returns null when URL identifier uses dangerous scheme', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: 'javascript:alert(1)',
                identifier_type: 'URL',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn();

        const { container } = render(
            <ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders identifier as fallback when fetch fails and no related_title exists', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 404,
        });

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            const link = screen.getByRole('link');
            expect(link).toHaveTextContent('10.5880/test');
            expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test');
        });
    });

    it('handles network errors gracefully', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
                related_title: 'Fallback Title',
            },
        ];

        global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            const link = screen.getByRole('link');
            expect(link).toHaveTextContent('Fallback Title');
        });
    });

    it('renders non-DOI supplementTo with related_title as link', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: 'http://example.com/model',
                identifier_type: 'URL',
                relation_type: 'IsSupplementTo',
                related_title: 'External Model Documentation',
            },
        ];

        global.fetch = vi.fn();

        render(<ModelDescriptionSection relatedIdentifiers={relatedIdentifiers} />);

        const link = screen.getByRole('link');
        expect(link).toHaveTextContent('External Model Documentation');
        expect(link).toHaveAttribute('href', 'http://example.com/model');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('renders non-DOI supplementTo with identifier as fallback when no related_title', () => {
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

        const link = screen.getByRole('link');
        expect(link).toHaveTextContent('http://example.com/model');
        expect(link).toHaveAttribute('href', 'http://example.com/model');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('resets stale citation state when supplementTo changes to non-DOI', async () => {
        const doiRelation = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Old Citation' }),
        });

        const { rerender } = render(
            <ModelDescriptionSection relatedIdentifiers={doiRelation} />,
        );

        await waitFor(() => {
            expect(screen.getByText('Old Citation')).toBeInTheDocument();
        });

        // Switch to non-DOI supplementTo
        const urlRelation = [
            {
                id: 2,
                identifier: 'http://example.com/new-model',
                identifier_type: 'URL',
                relation_type: 'IsSupplementTo',
            },
        ];

        rerender(<ModelDescriptionSection relatedIdentifiers={urlRelation} />);

        await waitFor(() => {
            // Old citation should be cleared, show identifier instead
            expect(screen.queryByText('Old Citation')).not.toBeInTheDocument();
            const link = screen.getByRole('link');
            expect(link).toHaveTextContent('http://example.com/new-model');
        });
    });
});
