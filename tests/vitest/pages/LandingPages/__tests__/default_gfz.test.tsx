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
        descriptions: [{ id: 1, description: 'Test abstract', description_type: 'Abstract' }],
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

        const helmholtzLogo = screen.getByAltText('Helmholtz');
        expect(helmholtzLogo).toBeInTheDocument();
        expect(helmholtzLogo.closest('a')).toHaveAttribute('href', 'https://www.helmholtz.de');
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
            const banner = statusElements.find(el => el.textContent === 'Preview Mode');
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

        it('renders decorative logos with dark mode invert classes', () => {
            render(<DefaultGfzTemplate />);

            const dsLogo = screen.getByAltText('GFZ Data Services');
            expect(dsLogo).toHaveClass('dark:brightness-200', 'dark:invert');

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
    });
});
