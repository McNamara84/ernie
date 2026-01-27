import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', () => ({
    usePage: vi.fn(),
}));

import { usePage } from '@inertiajs/react';

import DefaultGfzIgsnTemplate from '@/pages/LandingPages/default_gfz_igsn';

const mockUsePage = vi.mocked(usePage);

describe('DefaultGfzIgsnTemplate', () => {
    const mockResource = {
        id: 1,
        resource_type: { id: 1, name: 'PhysicalObject' },
        titles: [
            { id: 1, title: 'Rock Sample Core XYZ', title_type: 'MainTitle' },
            { id: 2, title: 'Collected from Potsdam Site', title_type: 'Subtitle' },
        ],
        descriptions: [],
        creators: [
            {
                id: 1,
                position: 1,
                affiliations: [],
                creatorable: {
                    type: 'Person',
                    id: 1,
                    given_name: 'John',
                    family_name: 'Doe',
                },
            },
        ],
        funding_references: [],
        subjects: [],
        related_identifiers: [],
        contact_persons: [],
        geo_locations: [],
        licenses: [],
    };

    const mockLandingPage = {
        id: 1,
        status: 'published',
        ftp_url: null, // No FTP URL for IGSN
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Layout Structure', () => {
        it('renders the main layout structure', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Check for main title
            expect(screen.getByText('Rock Sample Core XYZ')).toBeInTheDocument();
        });

        it('renders the GFZ Data Services logo', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const logo = screen.getByAltText('GFZ Data Services');
            expect(logo).toBeInTheDocument();
            expect(logo).toHaveAttribute('src', '/images/gfz-ds-logo.png');
        });

        it('renders the Legal Notice link', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const legalNoticeLink = screen.getByText('Legal Notice');
            expect(legalNoticeLink).toBeInTheDocument();
            expect(legalNoticeLink).toHaveAttribute('href', '/legal-notice');
        });

        it('renders footer with GFZ and Helmholtz logos', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const gfzLogo = screen.getByAltText('GFZ');
            expect(gfzLogo).toBeInTheDocument();
            expect(gfzLogo.closest('a')).toHaveAttribute('href', 'https://www.gfz.de');

            const helmholtzLogo = screen.getByAltText('Helmholtz');
            expect(helmholtzLogo).toBeInTheDocument();
            expect(helmholtzLogo.closest('a')).toHaveAttribute('href', 'https://www.helmholtz.de');
        });
    });

    describe('IGSN-specific Display', () => {
        it('displays IGSN as resource type label', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // The template should show "IGSN" not "PhysicalObject"
            expect(screen.getByText('IGSN')).toBeInTheDocument();
            expect(screen.queryByText('PhysicalObject')).not.toBeInTheDocument();
        });

        it('renders the main title', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Rock Sample Core XYZ')).toBeInTheDocument();
        });

        it('renders the subtitle when present', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Collected from Potsdam Site')).toBeInTheDocument();
        });
    });

    describe('Preview Mode', () => {
        it('shows preview banner when isPreview is true', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: true,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Preview Mode')).toBeInTheDocument();
        });

        it('does not show preview banner when isPreview is false', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.queryByText('Preview Mode')).not.toBeInTheDocument();
        });
    });

    describe('Simplified Content (No Abstract/Files sections)', () => {
        it('does not render Abstract section', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [{ id: 1, value: 'Sample abstract text', description_type: 'Abstract' }],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Abstract section should not be rendered in IGSN template
            expect(screen.queryByText('Abstract')).not.toBeInTheDocument();
            expect(screen.queryByText('Sample abstract text')).not.toBeInTheDocument();
        });

        it('does not render Files section', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: {
                        ...mockLandingPage,
                        ftp_url: 'https://datapub.gfz-potsdam.de/download/test',
                    },
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Files section should not be rendered in IGSN template
            expect(screen.queryByText('Files')).not.toBeInTheDocument();
            expect(screen.queryByText('Download')).not.toBeInTheDocument();
        });

        it('does not render Creators section', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Creators section header should not be present
            expect(screen.queryByText('Creators')).not.toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        it('handles missing subtitle gracefully', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        titles: [{ id: 1, title: 'Only Main Title', title_type: 'MainTitle' }],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Only Main Title')).toBeInTheDocument();
        });

        it('handles missing title with fallback', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        titles: [],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Untitled')).toBeInTheDocument();
        });

        it('handles null landingPage gracefully', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: null,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Should still render without crashing
            expect(screen.getByText('Rock Sample Core XYZ')).toBeInTheDocument();
        });
    });
});
