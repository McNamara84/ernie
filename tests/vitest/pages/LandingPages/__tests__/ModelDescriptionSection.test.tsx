import { render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ModelDescriptionSection } from '@/pages/LandingPages/components/ModelDescriptionSection';

describe('ModelDescriptionSection', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('returns null when no IsSupplementTo relation exists', () => {
        const { container } = render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/test',
                    identifier_type: 'DOI',
                    relation_type: 'References',
                },
            ]} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders the section title and persisted citation label', () => {
        render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/test',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                    citation_label: 'Smith, J. (2024). Test Model.',
                },
            ]} />,
        );

        expect(screen.getByRole('heading', { name: 'Model Description' })).toBeInTheDocument();
        expect(screen.getByText('Smith, J. (2024). Test Model.')).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('falls back to related_title when no citation label exists', () => {
        render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/test',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                    related_title: 'Fallback Model Title',
                },
            ]} />,
        );

        expect(screen.getByText('Fallback Model Title')).toBeInTheDocument();
    });

    it('falls back to the identifier when no citation label or related title exists', () => {
        render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/test',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                },
            ]} />,
        );

        expect(screen.getByText('10.5880/test')).toBeInTheDocument();
    });

    it('uses the first IsSupplementTo relation only', () => {
        render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/first',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                    citation_label: 'First citation',
                },
                {
                    id: 2,
                    identifier: '10.5880/second',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                    citation_label: 'Second citation',
                },
            ]} />,
        );

        expect(screen.getByText('First citation')).toBeInTheDocument();
        expect(screen.queryByText('Second citation')).not.toBeInTheDocument();
    });

    it('renders the resolved DOI link', () => {
        render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '10.5880/my-dataset',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                    citation_label: 'Test Citation',
                },
            ]} />,
        );

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/my-dataset');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('returns null when the identifier is empty or unsafe', () => {
        const { container: emptyContainer } = render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 1,
                    identifier: '',
                    identifier_type: 'DOI',
                    relation_type: 'IsSupplementTo',
                },
            ]} />,
        );

        const { container: unsafeContainer } = render(
            <ModelDescriptionSection relatedIdentifiers={[
                {
                    id: 2,
                    identifier: 'javascript:alert(1)',
                    identifier_type: 'URL',
                    relation_type: 'IsSupplementTo',
                },
            ]} />,
        );

        expect(emptyContainer.firstChild).toBeNull();
        expect(unsafeContainer.firstChild).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
    });
});