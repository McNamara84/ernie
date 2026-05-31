import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { PortalResultCard } from '@/components/portal/PortalResultCard';
import type { PortalCreator, PortalResource } from '@/types/portal';

/**
 * Factory to create a mock PortalResource for testing
 */
function createMockResource(overrides: Partial<PortalResource> = {}): PortalResource {
    return {
        id: 1,
        title: 'Test Resource Title',
        doi: '10.5880/GFZ.TEST.2024.001',
        abstract: null,
        resourceType: 'Dataset',
        resourceTypeSlug: 'dataset',
        isIgsn: false,
        year: 2024,
        landingPageUrl: '/landing/test-slug',
        creators: [{ name: 'Smith' }],
        geoLocations: [],
        ...overrides,
    };
}

describe('PortalResultCard', () => {
    describe('Basic Rendering', () => {
        it('renders resource title', () => {
            const resource = createMockResource({ title: 'Climate Data for Europe 2024' });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Climate Data for Europe 2024')).toBeInTheDocument();
        });

        it('renders resource DOI', () => {
            const resource = createMockResource({ doi: '10.5880/GFZ.SAMPLE.2024' });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('10.5880/GFZ.SAMPLE.2024')).toBeInTheDocument();
        });

        it('renders resource type badge', () => {
            const resource = createMockResource({ isIgsn: false, resourceType: 'Dataset' });
            render(<PortalResultCard resource={resource} />);

            // Badge shows resourceType for non-IGSN resources
            expect(screen.getByText('Dataset')).toBeInTheDocument();
        });

        it('renders publication year', () => {
            const resource = createMockResource({ year: 2023, creators: [{ name: 'Test' }] });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText(/2023/)).toBeInTheDocument();
        });

        it('handles missing DOI gracefully', () => {
            const resource = createMockResource({ doi: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.queryByText(/10\.5880/)).not.toBeInTheDocument();
        });

        it('handles missing year gracefully', () => {
            const resource = createMockResource({ year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.queryByText('•')).not.toBeInTheDocument();
        });

        it('keeps the title flexible and the metadata cluster fixed for long titles', () => {
            const resource = createMockResource({
                title: 'A very long dataset title that should truncate visually before pushing authors and publication year out of the visible result row area',
            });

            render(<PortalResultCard resource={resource} />);

            expect(screen.getByTestId('portal-result-title')).toHaveClass('min-w-0', 'flex-1', 'truncate');
            expect(screen.getByTestId('portal-result-meta')).toHaveClass('shrink-0');
        });
    });

    describe('Author Formatting (Citation Style)', () => {
        it('formats single author correctly', () => {
            const creators: PortalCreator[] = [{ name: 'Johnson' }];
            const resource = createMockResource({ creators, year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Johnson')).toBeInTheDocument();
        });

        it('formats two authors with ampersand', () => {
            const creators: PortalCreator[] = [{ name: 'Smith' }, { name: 'Jones' }];
            const resource = createMockResource({ creators, year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Smith & Jones')).toBeInTheDocument();
        });

        it('formats three or more authors as "et al."', () => {
            const creators: PortalCreator[] = [{ name: 'Miller' }, { name: 'Brown' }, { name: 'Wilson' }];
            const resource = createMockResource({ creators, year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Miller et al.')).toBeInTheDocument();
        });

        it('handles four authors with "et al."', () => {
            const creators: PortalCreator[] = [
                { name: 'Author1' },
                { name: 'Author2' },
                { name: 'Author3' },
                { name: 'Author4' },
            ];
            const resource = createMockResource({ creators, year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Author1 et al.')).toBeInTheDocument();
        });

        it('displays "Unknown" when no creators', () => {
            const resource = createMockResource({ creators: [], year: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Unknown')).toBeInTheDocument();
        });

        it('handles creator with empty name', () => {
            const creators: PortalCreator[] = [{ name: '' }];
            const resource = createMockResource({ creators, year: null });
            render(<PortalResultCard resource={resource} />);

            // Component shows 'Unknown' for empty/falsy names via || 'Unknown' in formatName
            expect(screen.getByText('Unknown')).toBeInTheDocument();
        });
    });

    describe('Type Badge Variants', () => {
        it('renders default badge variant for DOI resources', () => {
            const resource = createMockResource({ isIgsn: false, resourceType: 'Dataset' });
            render(<PortalResultCard resource={resource} />);

            const badge = screen.getByText('Dataset');
            expect(badge).toBeInTheDocument();
            // Badge should not have secondary variant class
            expect(badge).not.toHaveClass('bg-secondary');
        });

        it('renders secondary badge variant for IGSN resources', () => {
            const resource = createMockResource({ isIgsn: true, resourceType: 'PhysicalObject' });
            render(<PortalResultCard resource={resource} />);

            const badge = screen.getByText('IGSN');
            expect(badge).toBeInTheDocument();
        });
    });

    describe('Landing Page Link', () => {
        it('renders as clickable link when landingPageUrl exists', () => {
            const resource = createMockResource({ landingPageUrl: '/landing/my-resource' });
            render(<PortalResultCard resource={resource} />);

            const link = screen.getByRole('link');
            expect(link).toHaveAttribute('href', '/landing/my-resource');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('renders without link when landingPageUrl is null', () => {
            const resource = createMockResource({ landingPageUrl: null });
            render(<PortalResultCard resource={resource} />);

            expect(screen.queryByRole('link')).not.toBeInTheDocument();
        });

        it('card has hover styles when linked', () => {
            const resource = createMockResource({ landingPageUrl: '/landing/test' });
            render(<PortalResultCard resource={resource} />);

            const link = screen.getByRole('link');
            expect(link).toHaveClass('group');
        });

        it('shows full title, creators, and abstract in the hover preview', async () => {
            const user = userEvent.setup();
            const resource = createMockResource({
                title: 'A much longer full title than the row can comfortably show',
                abstract: 'This abstract gives searchers more context without opening the landing page.',
                creators: [
                    { name: 'Smith', givenName: 'Jane' },
                    { name: 'Jones', givenName: 'Max' },
                    { name: 'GFZ Data Services' },
                ],
            });

            render(<PortalResultCard resource={resource} />);

            await user.hover(screen.getByRole('link'));

            const preview = await screen.findByTestId('portal-result-preview');
            const scoped = within(preview);

            expect(scoped.getByText('A much longer full title than the row can comfortably show')).toBeInTheDocument();
            expect(scoped.getByText('Jane Smith, Max Jones, GFZ Data Services')).toBeInTheDocument();
            expect(scoped.getByText('This abstract gives searchers more context without opening the landing page.')).toBeInTheDocument();
        });

        it('shows the same preview on keyboard focus', async () => {
            const user = userEvent.setup();
            const resource = createMockResource({
                abstract: 'Keyboard users should get the same preview.',
            });

            render(<PortalResultCard resource={resource} />);

            await user.tab();

            const preview = await screen.findByTestId('portal-result-preview');
            expect(preview).toBeInTheDocument();
            expect(within(preview).getByText('Keyboard users should get the same preview.')).toBeInTheDocument();
        });

        it('omits the abstract block from the preview when no abstract is available', async () => {
            const user = userEvent.setup();
            const resource = createMockResource({ abstract: null });

            render(<PortalResultCard resource={resource} />);

            await user.hover(screen.getByRole('link'));

            const preview = await screen.findByTestId('portal-result-preview');
            expect(within(preview).queryByText('Abstract')).not.toBeInTheDocument();
        });
    });

    describe('Complex Resources', () => {
        it('renders fully populated resource', () => {
            const resource = createMockResource({
                id: 99,
                title: 'Comprehensive Geoscience Dataset',
                doi: '10.5880/GFZ.FULL.001',
                resourceType: 'Dataset',
                isIgsn: false,
                year: 2024,
                landingPageUrl: '/landing/full-resource',
                creators: [
                    { name: 'Harrison' },
                    { name: 'Martinez' },
                    { name: 'Chen' },
                ],
            });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Comprehensive Geoscience Dataset')).toBeInTheDocument();
            expect(screen.getByText('10.5880/GFZ.FULL.001')).toBeInTheDocument();
            expect(screen.getByText('Dataset')).toBeInTheDocument();
            // Year appears in the author row
            expect(screen.getByText('2024')).toBeInTheDocument();
            expect(screen.getByText('Harrison et al.')).toBeInTheDocument();
            expect(screen.getByRole('link')).toHaveAttribute('href', '/landing/full-resource');
        });

        it('renders IGSN physical object resource', () => {
            const resource = createMockResource({
                title: 'Rock Core Sample XYZ',
                doi: null,
                resourceType: 'PhysicalObject',
                isIgsn: true,
                year: null,
                landingPageUrl: null,
                creators: [{ name: 'Geology Lab' }],
            });
            render(<PortalResultCard resource={resource} />);

            expect(screen.getByText('Rock Core Sample XYZ')).toBeInTheDocument();
            expect(screen.getByText('IGSN')).toBeInTheDocument();
            expect(screen.getByText('Geology Lab')).toBeInTheDocument();
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
        });
    });
});
