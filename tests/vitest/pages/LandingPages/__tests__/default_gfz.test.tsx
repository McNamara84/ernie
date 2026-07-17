import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', () => ({
    usePage: vi.fn(),
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
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
        expect(logo).toHaveAttribute('src', '/images/gfz-ds-logo.png');
        expect(logo).toHaveClass('h-24', 'dark:grayscale', 'dark:invert', 'dark:mix-blend-screen');
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
                resource: mockResource,
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

    describe('Schema.org JSON-LD', () => {
        it('renders JSON-LD script tag when schemaOrgJsonLd is provided', () => {
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

            // The Head component renders children directly in our mock
            const scriptContent = JSON.stringify(schemaOrgJsonLd);
            expect(document.body.textContent).toContain(scriptContent);
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
            expect(logo).toHaveClass('h-24');
            expect(logo).not.toHaveClass('dark:grayscale');
            expect(logo).not.toHaveClass('dark:invert');
            expect(logo).not.toHaveClass('dark:mix-blend-screen');
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
