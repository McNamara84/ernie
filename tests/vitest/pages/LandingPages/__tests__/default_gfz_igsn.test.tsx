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
            expect(logo).toHaveClass('h-24');
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

    describe('Simplified Content (No Files section)', () => {
        it('renders Abstract section when descriptions are provided', () => {
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

            // Abstract content from descriptions is now rendered alongside General/Acquisition modules
            expect(screen.getByText('Sample abstract text')).toBeInTheDocument();
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

        it('renders Creators section when creators are provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [{ id: 1, value: 'Some abstract', description_type: 'Abstract' }],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Creators section is rendered inside the AbstractSection card
            expect(screen.getByText('Creators')).toBeInTheDocument();
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

    describe('General & Acquisition Modules', () => {
        it('renders General module with IGSN-specific fields', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        doi: '10.58050/IGSN-XYZ123',
                        igsn_metadata: {
                            id: 1,
                            sample_type: 'Rock',
                            material: 'Granite',
                            collection_method: 'Drilling',
                            collection_method_description: null,
                            sample_purpose: 'Tectonic study',
                            cruise_field_program: 'Alpine 2023',
                            parent: null,
                        },
                        igsn_classifications: [
                            { id: 1, value: 'Igneous' },
                            { id: 2, value: 'Plutonic' },
                        ],
                        funding_references: [
                            { id: 1, funder_name: 'DFG', award_title: 'Project Alpha', award_number: '123' },
                        ],
                        dates: [
                            { id: 1, date_type: 'Available', date_type_slug: 'Available', date_value: '2024-01-15', start_date: null, end_date: null, date_information: null },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('General')).toBeInTheDocument();
            expect(screen.getByText('Project Alpha')).toBeInTheDocument();
            expect(screen.getByText('Alpine 2023')).toBeInTheDocument();
            expect(screen.getByText('Rock')).toBeInTheDocument();
            expect(screen.getByText('10.58050/IGSN-XYZ123')).toBeInTheDocument();
            expect(screen.getByText('Tectonic study')).toBeInTheDocument();
            expect(screen.getByText('2024-01-15')).toBeInTheDocument();
        });

        it('renders Acquisition module with IGSN-specific fields', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        igsn_metadata: {
                            id: 1,
                            sample_type: null,
                            material: 'Basalt',
                            collection_method: 'Hand sampling',
                            collection_method_description: 'Surface outcrop',
                            sample_purpose: null,
                            cruise_field_program: null,
                            parent: null,
                        },
                        igsn_classifications: [
                            { id: 1, value: 'Igneous' },
                            { id: 2, value: 'Volcanic' },
                        ],
                        funding_references: [
                            { id: 1, funder_name: 'NSF', award_title: 'X', award_number: 'Y' },
                        ],
                        descriptions: [
                            { id: 1, value: 'Field comments here', description_type: 'Other' },
                        ],
                        contributors: [
                            {
                                id: 1,
                                position: 1,
                                affiliations: [],
                                contributorable: { type: 'Person', id: 1, given_name: 'Jane', family_name: 'Smith' },
                                contributor_types: ['Data Collector'],
                            },
                        ],
                        dates: [
                            { id: 1, date_type: 'Collected', date_type_slug: 'Collected', date_value: null, start_date: '2023-06-01', end_date: '2023-06-30', date_information: null },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Acquisition')).toBeInTheDocument();
            expect(screen.getByText('Basalt')).toBeInTheDocument();
            expect(screen.getByText('Igneous, Volcanic')).toBeInTheDocument();
            expect(screen.getByText('Hand sampling')).toBeInTheDocument();
            expect(screen.getByText('Surface outcrop')).toBeInTheDocument();
            expect(screen.getByText('NSF')).toBeInTheDocument();
            expect(screen.getByText('Field comments here')).toBeInTheDocument();
            expect(screen.getByText('Jane Smith')).toBeInTheDocument();
            expect(screen.getByText('2023-06-01')).toBeInTheDocument();
            expect(screen.getByText('2023-06-30')).toBeInTheDocument();
        });

        it('hides General and Acquisition modules when no IGSN data is provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.queryByText('General')).not.toBeInTheDocument();
            expect(screen.queryByText('Acquisition')).not.toBeInTheDocument();
        });

        it('renders Parent IGSN as link when parent landing page is published', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        doi: '10.58050/IGSN-CHILD',
                        igsn_metadata: {
                            id: 1,
                            sample_type: null,
                            material: null,
                            collection_method: null,
                            collection_method_description: null,
                            sample_purpose: null,
                            cruise_field_program: null,
                            parent: {
                                doi: '10.58050/IGSN-PARENT',
                                landing_page: {
                                    public_url: 'https://example.test/landing/parent-slug',
                                },
                            },
                        },
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const parentLink = screen.getByRole('link', { name: '10.58050/IGSN-PARENT' });
            expect(parentLink).toBeInTheDocument();
            expect(parentLink).toHaveAttribute('href', 'https://example.test/landing/parent-slug');
        });
    });
});
