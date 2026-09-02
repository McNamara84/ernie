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

import DefaultGfzTemplate from '@/pages/LandingPages/default_gfz';

const mockUsePage = vi.mocked(usePage);

describe('DefaultGfzTemplate', () => {
    const mockResource = {
        id: 1,
        resource_type: { id: 1, name: 'Dataset' },
        titles: [
            { id: 1, title: 'Test Dataset Title', title_type: 'MainTitle' },
            { id: 2, title: 'Test Subtitle', title_type: 'Subtitle' },
        ],
        descriptions: [{ id: 1, value: 'Test abstract', description_type: 'Abstract' }],
        creators: [],
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
        ftp_url: 'https://ftp.example.com/dataset',
    };

    beforeEach(() => {
        vi.clearAllMocks();
        document.title = '';
    });

    it('renders the main layout structure', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        // Check for main structural elements
        expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
        expect(screen.getByText('Abstract')).toBeInTheDocument();
    });

    it('renders the server-provided document title through Inertia Head', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                documentTitle: 'Test Dataset Title | GFZ Data Services',
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(document.title).toBe('Test Dataset Title | GFZ Data Services');
        expect(document.head.querySelector('meta[name="robots"]')).not.toBeInTheDocument();
    });

    it('shows preview banner when isPreview is true', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: true,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Preview Mode')).toBeInTheDocument();
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('data-inertia', 'landing-page-robots');
    });

    it('does not show preview banner when isPreview is false', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.queryByText('Preview Mode')).not.toBeInTheDocument();
    });

    it('renders the GFZ Data Services logo', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        const logo = screen.getByAltText('GFZ Data Services');
        expect(logo).toBeInTheDocument();
        expect(logo).toHaveAttribute('src', '/images/GFZ-Header_2026.webp');
        expect(logo).toHaveClass('h-auto', 'w-auto', 'max-w-full', 'object-contain', 'dark:grayscale', 'dark:invert', 'dark:mix-blend-screen');
        expect(logo).not.toHaveClass('h-24');
    });

    it('renders the Legal Notice link', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

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

        render(<DefaultGfzTemplate />);

        const gfzLogo = screen.getByAltText('GFZ');
        expect(gfzLogo).toBeInTheDocument();
        expect(gfzLogo.closest('a')).toHaveAttribute('href', 'https://www.gfz.de');
        expect(gfzLogo.closest('picture')?.querySelector('source')).toHaveAttribute('srcset', '/images/gfz-logo_en.svg');

        const helmholtzLogo = screen.getByAltText('Helmholtz');
        expect(helmholtzLogo).toBeInTheDocument();
        expect(helmholtzLogo.closest('a')).toHaveAttribute('href', 'https://www.helmholtz.de');
        expect(helmholtzLogo.closest('picture')?.querySelector('source')).toHaveAttribute('srcset', '/images/helmholtz-logo-white.svg');
    });

    it('renders the main title', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
    });

    it('renders subtitle when available', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Test Subtitle')).toBeInTheDocument();
    });

    it('defaults to "Untitled" when no main title exists', () => {
        const resourceWithoutTitle = {
            ...mockResource,
            titles: [],
        };

        mockUsePage.mockReturnValue({
            props: {
                resource: resourceWithoutTitle,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Untitled')).toBeInTheDocument();
    });

    it('renders resource type name', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Dataset')).toBeInTheDocument();
    });

    it('defaults to "Other" when resource type is missing', () => {
        const resourceWithoutType = {
            ...mockResource,
            resource_type: null,
        };

        mockUsePage.mockReturnValue({
            props: {
                resource: resourceWithoutType,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Other')).toBeInTheDocument();
    });

    it('handles null landingPage gracefully', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: null,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        // Should not throw
        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
    });

    it('handles empty arrays in resource properties', () => {
        const emptyResource = {
            id: 1,
            resource_type: null,
            titles: [],
            descriptions: [],
            creators: [],
            funding_references: [],
            subjects: [],
            related_identifiers: [],
            contact_persons: [],
            geo_locations: [],
            licenses: [],
        };

        mockUsePage.mockReturnValue({
            props: {
                resource: emptyResource,
                landingPage: null,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        // Should not throw
        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Untitled')).toBeInTheDocument();
    });

    it('finds main title when title_type is null (legacy format)', () => {
        const resourceWithNullTitleType = {
            ...mockResource,
            titles: [{ id: 1, title: 'Legacy Title', title_type: null }],
        };

        mockUsePage.mockReturnValue({
            props: {
                resource: resourceWithNullTitleType,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Legacy Title')).toBeInTheDocument();
    });

    it('renders Files section', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Files')).toBeInTheDocument();
    });

    it('renders Abstract section', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.getByText('Abstract')).toBeInTheDocument();
    });

    it('renders download link when ftp_url is provided', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: mockResource,
                landingPage: mockLandingPage,
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        const downloadLink = screen.getByText('Download data and description');
        expect(downloadLink).toBeInTheDocument();
        expect(downloadLink.closest('a')).toHaveAttribute('href', 'https://ftp.example.com/dataset');
    });

    it('omits the Files section when downloads are unavailable', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: {
                    ...mockResource,
                    licenses: [
                        {
                            id: 1,
                            resource_right_id: 10,
                            name: 'Creative Commons Attribution Non Commercial 4.0 International',
                            spdx_id: 'CC-BY-NC-4.0',
                            reference: 'https://creativecommons.org/licenses/by-nc/4.0/',
                            source: 'catalog',
                        },
                    ],
                },
                landingPage: {
                    ...mockLandingPage,
                    downloads_unavailable: true,
                    files: [
                        {
                            id: 1,
                            url: 'https://ftp.example.com/dataset/supplement.csv',
                            position: 0,
                        },
                    ],
                    links: [
                        {
                            id: 1,
                            url: 'https://example.org/repository',
                            label: 'Repository',
                            position: 0,
                        },
                    ],
                },
                isPreview: false,
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.queryByText('Files')).not.toBeInTheDocument();
        expect(screen.queryByText('Download data and description')).not.toBeInTheDocument();
        expect(screen.queryByText('Repository')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'License & Rights' })).toBeInTheDocument();
        expect(screen.getByText(/Creative Commons Attribution Non Commercial 4\.0 International/)).toBeInTheDocument();
    });

    describe('accessibility', () => {
        beforeEach(() => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);
        });

        it('renders a skip navigation link', () => {
            render(<DefaultGfzTemplate />);

            const skipLink = screen.getByText('Skip to main content');
            expect(skipLink).toBeInTheDocument();
            expect(skipLink).toHaveAttribute('href', '#main-content');
        });

        it('renders a main landmark element', () => {
            render(<DefaultGfzTemplate />);

            const main = screen.getByRole('main');
            expect(main).toBeInTheDocument();
            expect(main).toHaveAttribute('id', 'main-content');
        });

        it('renders preview banner with role="status"', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: true,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            const statusElements = screen.getAllByRole('status');
            const banner = statusElements.find((el) => el.textContent === 'Preview Mode');
            expect(banner).toBeTruthy();
        });

        it('renders order classes for mobile-first reading order', () => {
            render(<DefaultGfzTemplate />);

            // The abstract column (order-1 on mobile) comes before files column (order-2)
            const main = screen.getByRole('main');
            const grid = main.querySelector('.grid');
            expect(grid).toBeTruthy();

            const columns = grid!.querySelectorAll(':scope > div');
            expect(columns.length).toBe(2);

            // First column in DOM has order-1 (abstract - first on mobile)
            expect(columns[0]).toHaveClass('order-1');
            // Second column in DOM has order-2 (files - second on mobile)
            expect(columns[1]).toHaveClass('order-2');
        });

        it('renders default and footer logos with the expected dark mode strategy', () => {
            render(<DefaultGfzTemplate />);

            const dsLogo = screen.getByAltText('GFZ Data Services');
            expect(dsLogo).toHaveClass('dark:grayscale', 'dark:invert', 'dark:mix-blend-screen');

            // GFZ footer logo uses DarkModeImage (<picture>) instead of CSS filter
            const gfzLogo = screen.getByAltText('GFZ');
            const picture = gfzLogo.closest('picture');
            expect(picture).toBeInTheDocument();
            expect(picture).toHaveAttribute('data-slot', 'dark-mode-image');
            const source = picture!.querySelector('source');
            expect(source).toHaveAttribute('media', '(prefers-color-scheme: dark)');
        });
    });

    describe('server-owned machine metadata', () => {
        it('does not duplicate JSON-LD client-side when a legacy prop is provided', () => {
            const schemaOrgJsonLd = { '@context': 'https://schema.org', '@type': 'Dataset', name: 'Test' };

            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    schemaOrgJsonLd,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(document.querySelector('script[type="application/ld+json"]')).not.toBeInTheDocument();
        });

        it('does not duplicate canonical metadata discovery links in the React head', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    metadataLinks: [
                        {
                            format: 'datacite-xml',
                            standard: 'DataCite',
                            label: 'DataCite XML',
                            url: 'https://example.com/metadata/datacite.xml',
                            mediaType: 'application/xml',
                            profile: null,
                        },
                        {
                            format: 'iso19115-3',
                            standard: 'ISO 19115-3',
                            label: 'ISO 19115-3:2023 XML',
                            url: 'https://example.com/metadata/iso-19115-3.xml',
                            mediaType: 'application/xml',
                            profile: 'https://schemas.isotc211.org/19115/-1/mdb/1.3',
                        },
                    ],
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(document.querySelector('link[rel="alternate"]')).not.toBeInTheDocument();
            expect(document.querySelector('link[rel="describedby"]')).not.toBeInTheDocument();
            expect(screen.getByText('Download Metadata')).toBeInTheDocument();
        });

        it('does not render JSON-LD script tag when schemaOrgJsonLd is not provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(document.body.innerHTML).not.toContain('application/ld+json');
        });
    });

    describe('fallback values', () => {
        it('uses landingPage status when not in preview mode', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: { ...mockLandingPage, status: 'draft' },
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            // Should not throw - status is used internally by ResourceHero
            render(<DefaultGfzTemplate />);
            expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
        });

        it('handles undefined resource arrays with fallback empty arrays', () => {
            const resourceWithUndefined = {
                id: 1,
                resource_type: { id: 1, name: 'Dataset' },
                titles: [{ id: 1, title: 'Test', title_type: 'MainTitle' }],
                // Intentionally omit all optional arrays
            };

            mockUsePage.mockReturnValue({
                props: {
                    resource: resourceWithUndefined,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            // Should not throw
            expect(() => render(<DefaultGfzTemplate />)).not.toThrow();
        });

        it('passes correct jsonLdExportUrl when landingPage has public_url', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: { ...mockLandingPage, public_url: '/10.5880/test' },
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            // Render should succeed with jsonLdExportUrl derived from public_url
            render(<DefaultGfzTemplate />);
            expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
        });

        it('uses customLogoUrl when provided', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    customLogoUrl: 'https://cdn.example/custom-logo.png',
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            const logo = screen.getByAltText('GFZ Data Services');

            expect(logo).toHaveAttribute('src', 'https://cdn.example/custom-logo.png');
            expect(logo).toHaveClass('h-auto', 'w-auto', 'max-w-full', 'object-contain');
            expect(logo).not.toHaveClass('h-24');
            expect(logo).not.toHaveClass('dark:grayscale');
            expect(logo).not.toHaveClass('dark:invert');
            expect(logo).not.toHaveClass('dark:mix-blend-screen');
        });

        it('renders standalone Resource modules in their configured target columns', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        geo_locations: [
                            {
                                id: 1,
                                place: 'Potsdam',
                                point_longitude: 13.0645,
                                point_latitude: 52.3906,
                                west_bound_longitude: null,
                                east_bound_longitude: null,
                                south_bound_latitude: null,
                                north_bound_latitude: null,
                                polygon_points: null,
                                geo_type: null,
                                elevation: null,
                                elevation_unit: null,
                                location_type: null,
                                location_description: null,
                                locality_description: null,
                                country: 'Germany',
                                province: null,
                                county: null,
                                city: 'Potsdam',
                            },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        leftColumn: ['location', 'licenses', 'citation', 'dates', 'contact', 'model_description', 'related_work'],
                        rightColumn: [
                            'files',
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
                        ],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            const leftColumn = screen.getByTestId('landing-page-left-column');
            const rightColumn = screen.getByTestId('landing-page-right-column');
            expect(within(leftColumn).getByTestId('geolocation-section')).toBeInTheDocument();
            expect(within(leftColumn).queryByTestId('files-section')).not.toBeInTheDocument();
            expect(within(rightColumn).getByTestId('files-section')).toBeInTheDocument();
            expect(within(rightColumn).queryByTestId('geolocation-section')).not.toBeInTheDocument();
        });

        it('retains one shared metadata card per occupied column', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [
                            { id: 1, value: 'Left abstract', description_type: 'Abstract' },
                            { id: 2, value: 'Left methods', description_type: 'Methods' },
                            { id: 3, value: 'Right technical details', description_type: 'TechnicalInfo' },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        leftColumn: ['abstract', 'methods', 'files', 'licenses', 'citation', 'dates', 'contact', 'model_description', 'related_work'],
                        rightColumn: [
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

            render(<DefaultGfzTemplate />);

            const leftColumn = screen.getByTestId('landing-page-left-column');
            const rightColumn = screen.getByTestId('landing-page-right-column');
            expect(screen.getAllByTestId('metadata-section')).toHaveLength(2);
            expect(within(leftColumn).getAllByTestId('metadata-section')).toHaveLength(1);
            expect(within(leftColumn).getByText('Left abstract')).toBeInTheDocument();
            expect(within(leftColumn).getByText('Left methods')).toBeInTheDocument();
            expect(within(leftColumn).queryByText('Right technical details')).not.toBeInTheDocument();
            expect(within(rightColumn).getAllByTestId('metadata-section')).toHaveLength(1);
            expect(within(rightColumn).getByText('Right technical details')).toBeInTheDocument();
        });

        it('keeps the canonical Resource layout in one metadata card on the right', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: mockResource,
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(within(screen.getByTestId('landing-page-left-column')).queryByTestId('metadata-section')).not.toBeInTheDocument();
            expect(within(screen.getByTestId('landing-page-right-column')).getAllByTestId('metadata-section')).toHaveLength(1);
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
                        rightColumn: ['location'],
                        leftColumn: ['files', 'contact', 'model_description', 'related_work'],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
            expect(screen.queryByText('Abstract')).not.toBeInTheDocument();
        });

        it('renders separate description modules in the configured order', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [
                            { id: 1, value: 'Abstract block', description_type: 'Abstract' },
                            { id: 2, value: 'Methods block', description_type: 'Methods' },
                            { id: 3, value: 'Technical block', description_type: 'TechnicalInfo' },
                        ],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: {
                        rightColumn: [
                            'methods',
                            'abstract',
                            'technical_info',
                            'creators',
                            'contributors',
                            'funders',
                            'keywords',
                            'metadata_download',
                            'location',
                        ],
                        leftColumn: ['files', 'contact', 'model_description', 'related_work'],
                    },
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            const methodsHeading = screen.getByText('Methods');
            const abstractHeading = screen.getByText('Abstract');
            const technicalHeading = screen.getByText('Technical Information');

            expect(methodsHeading.compareDocumentPosition(abstractHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(abstractHeading.compareDocumentPosition(technicalHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(screen.getByText('Technical block')).toBeInTheDocument();
        });

        it('renders non-abstract descriptions when no abstract exists', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: {
                        ...mockResource,
                        descriptions: [{ id: 1, value: 'Only methods', description_type: 'Methods' }],
                    },
                    landingPage: mockLandingPage,
                    isPreview: false,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(screen.getByText('Methods')).toBeInTheDocument();
            expect(screen.getByText('Only methods')).toBeInTheDocument();
        });
    });

    it('applies type visibility only to Dates and Related Work while preserving Model Description', () => {
        mockUsePage.mockReturnValue({
            props: {
                resource: {
                    ...mockResource,
                    dates: [
                        {
                            id: 1,
                            date_type: 'Created',
                            date_type_slug: 'Created',
                            date_value: '2026-01-15',
                            start_date: null,
                            end_date: null,
                            date_information: null,
                        },
                    ],
                    related_identifiers: [
                        {
                            id: 1,
                            identifier: '10.5880/supplement',
                            identifier_type: 'DOI',
                            relation_type: 'IsSupplementTo',
                            citation_label: 'Visible model supplement',
                        },
                        {
                            id: 2,
                            identifier: '10.5880/reference',
                            identifier_type: 'DOI',
                            relation_type: 'References',
                            citation_label: 'Hidden related work',
                        },
                    ],
                },
                landingPage: mockLandingPage,
                isPreview: false,
                typeVisibility: {
                    excludedDateTypes: ['Created'],
                    excludedRelationTypes: ['References'],
                },
            },
        } as unknown as ReturnType<typeof usePage>);

        render(<DefaultGfzTemplate />);

        expect(screen.queryByRole('heading', { name: 'Dates' })).not.toBeInTheDocument();
        expect(screen.queryByText('Hidden related work')).not.toBeInTheDocument();
        expect(screen.getByText('Visible model supplement')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Dataset Description' })).toBeInTheDocument();
    });

    describe('citation section order', () => {
        const resourceWithDate = {
            ...mockResource,
            dates: [
                {
                    id: 1,
                    date_type: 'Created',
                    date_type_slug: 'Created',
                    date_value: '2026-01-15',
                    start_date: null,
                    end_date: null,
                    date_information: null,
                },
            ],
        };

        const renderWithLeftOrder = (leftColumn?: string[]) => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: resourceWithDate,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    sectionOrder: leftColumn ? { leftColumn, rightColumn: [] } : null,
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);
        };

        it('renders the default order as Files, Cite this Resource, Dates', () => {
            renderWithLeftOrder();

            const files = screen.getByRole('heading', { name: 'Files' });
            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });
            const dates = screen.getByRole('heading', { name: 'Dates' });

            expect(files.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(citation.compareDocumentPosition(dates) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('appends citation after an old custom order that does not contain it', () => {
            renderWithLeftOrder(['dates', 'files', 'contact', 'model_description', 'related_work']);

            const dates = screen.getByRole('heading', { name: 'Dates' });
            const files = screen.getByRole('heading', { name: 'Files' });
            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });

            expect(dates.compareDocumentPosition(files) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(files.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('preserves a configured custom citation position', () => {
            renderWithLeftOrder(['citation', 'files', 'dates', 'contact', 'model_description', 'related_work']);

            const citation = screen.getByRole('heading', { name: 'Cite this Resource' });
            const files = screen.getByRole('heading', { name: 'Files' });
            const dates = screen.getByRole('heading', { name: 'Dates' });

            expect(citation.compareDocumentPosition(files) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(files.compareDocumentPosition(dates) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('passes server-rendered official citations into the module', () => {
            mockUsePage.mockReturnValue({
                props: {
                    resource: resourceWithDate,
                    landingPage: mockLandingPage,
                    isPreview: false,
                    citationStyles: [
                        {
                            id: 'apa-7',
                            label: 'APA 7',
                            available: true,
                            html: '<div class="csl-entry"><em>Server APA citation</em></div>',
                            text: 'Server APA citation',
                        },
                    ],
                },
            } as unknown as ReturnType<typeof usePage>);

            render(<DefaultGfzTemplate />);

            expect(screen.getByTestId('citation-content')).toHaveAttribute('data-citation-style', 'apa-7');
            expect(screen.getByText('Server APA citation').tagName).toBe('EM');
        });
    });
});
