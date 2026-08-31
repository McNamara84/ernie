import { render, screen, within } from '@tests/vitest/utils/render';
import { Children, cloneElement, isValidElement, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', () => ({
    usePage: vi.fn(),
    Head: ({ title, children }: { title?: string; children?: ReactNode }) => {
        if (title !== undefined) document.title = title;

        const managedChildren = Children.map(children, (child) => {
            if (!isValidElement<Record<string, unknown>>(child)) return child;

            return cloneElement(child, {
                'data-inertia': child.props['head-key'] ?? '',
                'head-key': undefined,
            });
        });

        return createPortal(managedChildren, document.head);
    },
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
        document.title = '';
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
            expect(document.head.querySelector('meta[name="robots"]')).not.toBeInTheDocument();
        });

        it('renders the server-provided preview title through Inertia Head', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    documentTitle: 'Preview: Rock Sample Core XYZ | GFZ Data Services',
                    landingPage: mockLandingPage,
                    isPreview: true,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(document.title).toBe('Preview: Rock Sample Core XYZ | GFZ Data Services');
            expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
            expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('data-inertia', 'landing-page-robots');
        });

        it('renders License & Rights independently of the unavailable Files module', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        licenses: [
                            {
                                id: null,
                                resource_right_id: 15,
                                name: 'Repository permission required.',
                                spdx_id: null,
                                reference: null,
                                source: 'raw',
                            },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.queryByRole('heading', { name: 'Files' })).not.toBeInTheDocument();
            expect(screen.getByRole('heading', { name: 'License & Rights' })).toBeInTheDocument();
            expect(screen.getByText('Repository permission required.')).toBeInTheDocument();
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
            expect(logo).toHaveClass('h-24', 'max-w-full', 'object-contain', 'dark:grayscale', 'dark:invert', 'dark:mix-blend-screen');
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
            expect(gfzLogo.closest('picture')?.querySelector('source')).toHaveAttribute('srcset', '/images/gfz-logo_en.svg');

            const helmholtzLogo = screen.getByAltText('Helmholtz');
            expect(helmholtzLogo).toBeInTheDocument();
            expect(helmholtzLogo.closest('a')).toHaveAttribute('href', 'https://www.helmholtz.de');
            expect(helmholtzLogo.closest('picture')?.querySelector('source')).toHaveAttribute('srcset', '/images/helmholtz-logo-white.svg');
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

        it('uses the local sample name as a presentation-only fallback for a :tba title', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        titles: [{ id: 1, title: ' :TBA ', title_type: 'MainTitle' }],
                        igsn_metadata: {
                            igsn: 'GFLMU0020',
                            name: 'ODG_1B_1',
                            user_code: null,
                            sample_type: null,
                            material: null,
                            cruise_field_program: null,
                            sample_purpose: null,
                            collection_method: null,
                            collection_method_description: null,
                            parent: null,
                        },
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByRole('heading', { level: 1, name: 'ODG_1B_1' })).toBeInTheDocument();
            expect(screen.queryByRole('heading', { level: 1, name: /tba/i })).not.toBeInTheDocument();
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
                        doi: '10.60510/igsn-xyz123',
                        igsn_metadata: {
                            id: 1,
                            igsn: 'IGSN-XYZ123',
                            name: 'Local sample XYZ',
                            user_code: 'Project Alpha',
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
                        funding_references: [{ id: 1, funder_name: 'DFG', award_title: 'Project Alpha', award_number: '123' }],
                        dates: [
                            {
                                id: 1,
                                date_type: 'Available',
                                date_type_slug: 'Available',
                                date_value: '2024-01-15',
                                start_date: null,
                                end_date: null,
                                date_information: null,
                            },
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
            expect(screen.getByText('IGSN-XYZ123')).toBeInTheDocument();
            expect(screen.getByText('Local sample XYZ')).toBeInTheDocument();
            expect(screen.queryByText(/10\.60510\/igsn-xyz123/i)).not.toBeInTheDocument();
            expect(screen.getByText('Tectonic study')).toBeInTheDocument();
            expect(screen.getAllByText('2024-01-15').length).toBeGreaterThan(0);
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
                            material_descriptions: ['Fine-grained volcanic rock'],
                            comments: ['Field comments here'],
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
                        funding_references: [{ id: 1, funder_name: 'NSF', award_title: 'X', award_number: 'Y' }],
                        descriptions: [{ id: 1, value: 'DataCite Other description', description_type: 'Other' }],
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
                            {
                                id: 1,
                                date_type: 'Collected',
                                date_type_slug: 'Collected',
                                date_value: null,
                                start_date: '2023-06-01',
                                end_date: '2023-06-30',
                                date_information: null,
                            },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const acquisitionHeading = screen.getByText('Acquisition');
            const acquisitionCard = acquisitionHeading.closest('[data-slot="landing-page-card"]');

            expect(acquisitionCard).not.toBeNull();

            const acquisition = within(acquisitionCard as HTMLElement);

            expect(acquisitionHeading).toBeInTheDocument();
            expect(acquisition.getByText('Basalt')).toBeInTheDocument();
            expect(acquisition.getByText('Basalt Classification')).toBeInTheDocument();
            expect(acquisition.getByText('Igneous, Volcanic')).toBeInTheDocument();
            expect(acquisition.getByText('Description')).toBeInTheDocument();
            expect(acquisition.queryByText('Basalt Description')).not.toBeInTheDocument();
            expect(acquisition.getByText('Fine-grained volcanic rock')).toBeInTheDocument();
            expect(acquisition.getByText('Hand sampling')).toBeInTheDocument();
            expect(acquisition.getByText('Collection Method Description')).toBeInTheDocument();
            expect(acquisition.getByText('Surface outcrop')).toBeInTheDocument();
            expect(acquisition.getByText('NSF')).toBeInTheDocument();
            expect(acquisition.getByText('Field comments here')).toBeInTheDocument();
            expect(acquisition.queryByText('DataCite Other description')).not.toBeInTheDocument();
            expect(acquisition.getByText('Jane Smith')).toBeInTheDocument();
            expect(acquisition.getByText('Start Date')).toBeInTheDocument();
            expect(acquisition.getByText('2023-06-01')).toBeInTheDocument();
            expect(acquisition.getByText('End Date')).toBeInTheDocument();
            expect(acquisition.getByText('2023-06-30')).toBeInTheDocument();
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
                        doi: '10.60510/igsn-child',
                        igsn_metadata: {
                            id: 1,
                            igsn: 'IGSN-CHILD',
                            sample_type: null,
                            material: null,
                            collection_method: null,
                            collection_method_description: null,
                            sample_purpose: null,
                            cruise_field_program: null,
                            parent: {
                                igsn: 'IGSN-PARENT',
                                doi: '10.60510/igsn-parent',
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

            const parentLink = screen.getByRole('link', { name: 'IGSN-PARENT' });
            expect(parentLink).toBeInTheDocument();
            expect(parentLink).toHaveAttribute('href', 'https://example.test/landing/parent-slug');
        });
    });

    describe('Template customisation props', () => {
        it('uses customLogoUrl when provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    customLogoUrl: 'https://cdn.example/custom.png',
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const logo = screen.getByAltText('GFZ Data Services');

            expect(logo).toHaveAttribute('src', 'https://cdn.example/custom.png');
            expect(logo).toHaveClass('h-auto', 'w-auto', 'max-w-full', 'object-contain');
            expect(logo).not.toHaveClass('h-24');
            expect(logo).not.toHaveClass('dark:grayscale');
            expect(logo).not.toHaveClass('dark:invert');
            expect(logo).not.toHaveClass('dark:mix-blend-screen');
        });

        it('respects sectionOrder.leftColumn override', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        doi: '10.58050/IGSN-X',
                        igsn_metadata: {
                            id: 1,
                            sample_type: 'Rock',
                            material: 'Granite',
                            collection_method: null,
                            collection_method_description: null,
                            sample_purpose: null,
                            cruise_field_program: null,
                            parent: null,
                        },
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        leftColumn: ['files', 'acquisition', 'general', 'contact', 'model_description', 'related_work'],
                        rightColumn: [
                            'abstract',
                            'methods',
                            'technical_info',
                            'series_information',
                            'table_of_contents',
                            'other',
                            'creators',
                            'contributors',
                            'funders',
                            'keywords',
                            'metadata_download',
                            'location',
                        ],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const general = screen.getByText('General');
            const acquisition = screen.getByText('Acquisition');

            // Acquisition should appear before General in the DOM with the override
            expect(acquisition.compareDocumentPosition(general) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('does not duplicate server-rendered schema.org JSON-LD when a legacy prop is provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    schemaOrgJsonLd: { '@context': 'https://schema.org', '@type': 'Dataset', name: 'Test' },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const script = document.querySelector('script[type="application/ld+json"]');
            expect(script).not.toBeInTheDocument();
        });

        it('falls back to "published" status when landingPage has no status and not in preview', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: { id: 1, ftp_url: null },
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            // Should still render the page with main title; status defaulted internally
            expect(screen.getByText('Rock Sample Core XYZ')).toBeInTheDocument();
        });

        it('handles a right column order that only contains location', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        leftColumn: ['general', 'acquisition', 'contact', 'model_description', 'related_work'],
                        rightColumn: ['location'],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByText('Rock Sample Core XYZ')).toBeInTheDocument();
            expect(screen.queryByText('Abstract')).not.toBeInTheDocument();
        });

        it('renders methods before abstract when the right column order requests it', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [
                            { id: 1, value: 'IGSN abstract', description_type: 'Abstract' },
                            { id: 2, value: 'IGSN methods', description_type: 'Methods' },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        leftColumn: ['general', 'acquisition', 'contact', 'model_description', 'related_work'],
                        rightColumn: [
                            'methods',
                            'abstract',
                            'technical_info',
                            'series_information',
                            'table_of_contents',
                            'other',
                            'creators',
                            'contributors',
                            'funders',
                            'keywords',
                            'metadata_download',
                            'location',
                        ],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const methodsHeading = screen.getByText('Methods');
            const abstractHeading = screen.getByText('Abstract');
            expect(methodsHeading.compareDocumentPosition(abstractHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(screen.getByText('IGSN methods')).toBeInTheDocument();
        });
    });

    describe('citation section order', () => {
        const fullyVisibleResource = {
            ...mockResource,
            doi: '10.60510/igsn-order',
            igsn_metadata: {
                id: 1,
                igsn: 'IGSN-ORDER',
                sample_type: 'Rock',
                material: 'Granite',
                collection_method: 'Drilling',
                collection_method_description: null,
                sample_purpose: null,
                cruise_field_program: null,
                parent: null,
            },
            igsn_classifications: [],
            dates: [
                {
                    id: 1,
                    date_type: 'Created',
                    date_type_slug: 'Created',
                    date_value: '2026-02-01',
                    start_date: null,
                    end_date: null,
                    date_information: null,
                },
            ],
        };

        const renderWithLeftOrder = (leftColumn?: string[]) => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: fullyVisibleResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: leftColumn ? { leftColumn, rightColumn: [] } : null,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);
        };

        it('renders the default order as General, Acquisition, Cite this Resource, Dates', () => {
            renderWithLeftOrder();

            const general = screen.getByRole('heading', { name: 'General' });
            const acquisition = screen.getByRole('heading', { name: 'Acquisition' });
            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });
            const dates = screen.getByRole('heading', { name: 'Dates' });

            expect(general.compareDocumentPosition(acquisition) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(acquisition.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(citation.compareDocumentPosition(dates) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('appends citation after an old custom IGSN order that does not contain it', () => {
            renderWithLeftOrder(['dates', 'acquisition', 'general', 'contact', 'model_description', 'related_work']);

            const dates = screen.getByRole('heading', { name: 'Dates' });
            const acquisition = screen.getByRole('heading', { name: 'Acquisition' });
            const general = screen.getByRole('heading', { name: 'General' });
            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });

            expect(dates.compareDocumentPosition(acquisition) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(acquisition.compareDocumentPosition(general) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(general.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('preserves a configured custom citation position for IGSN', () => {
            renderWithLeftOrder(['general', 'citation', 'acquisition', 'dates', 'contact', 'model_description', 'related_work']);

            const general = screen.getByRole('heading', { name: 'General' });
            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });
            const acquisition = screen.getByRole('heading', { name: 'Acquisition' });

            expect(general.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(citation.compareDocumentPosition(acquisition) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(screen.queryByRole('heading', { name: 'Files' })).not.toBeInTheDocument();
        });

        it('passes server-rendered official citations into the IGSN module', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: fullyVisibleResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    citationStyles: [
                        {
                            id: 'apa-7',
                            label: 'APA 7',
                            available: true,
                            html: '<div class="csl-entry"><em>IGSN APA citation</em></div>',
                            text: 'IGSN APA citation',
                        },
                    ],
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getByTestId('citation-content')).toHaveAttribute('data-citation-style', 'apa-7');
            expect(screen.getByText('IGSN APA citation').tagName).toBe('EM');
        });

        it('renders the sample image in either configured column', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...fullyVisibleResource,
                        igsn_metadata: {
                            ...fullyVisibleResource.igsn_metadata,
                            sample_image: { url: '/storage/igsn-sample-images/gfso273n39/image.jpg', hosting: 'managed' },
                        },
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: { leftColumn: ['sample_image'], rightColumn: [] },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(within(screen.getByTestId('landing-page-left-column')).getByRole('heading', { name: 'Sample Image' })).toBeInTheDocument();
            expect(within(screen.getByTestId('landing-page-right-column')).queryByRole('heading', { name: 'Sample Image' })).not.toBeInTheDocument();
        });

        it('renders independently movable metadata modules as separate cards', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...fullyVisibleResource,
                        descriptions: [{ id: 1, value: 'Independent abstract', description_type: 'Abstract' }],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            const abstractCard = screen.getByText('Independent abstract').closest('[data-slot="landing-page-card"]');
            const creatorsCard = screen.getByRole('heading', { name: 'Creators' }).closest('[data-slot="landing-page-card"]');
            expect(abstractCard).not.toBeNull();
            expect(creatorsCard).not.toBeNull();
            expect(abstractCard).not.toBe(creatorsCard);
        });

        it('does not render cards for independently placed metadata modules without content', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...fullyVisibleResource,
                        creators: [],
                        contributors: [],
                        descriptions: [],
                        funding_references: [],
                        subjects: [],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzIgsnTemplate />);

            expect(screen.getAllByTestId('metadata-section')).toHaveLength(1);
            expect(screen.getByRole('heading', { name: 'Download Metadata' })).toBeInTheDocument();
        });
    });
});
