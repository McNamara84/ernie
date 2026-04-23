import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SetupIgsnLandingPageModal from '@/components/landing-pages/modals/SetupIgsnLandingPageModal';
import type { LandingPageConfig } from '@/types/landing-page';

// Mock dependencies
vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const put = vi.fn();
    const deleteMethod = vi.fn();
    const isAxiosError = vi.fn((value: unknown): value is { isAxiosError: true } => {
        return typeof value === 'object' && value !== null && (value as { isAxiosError?: boolean }).isAxiosError === true;
    });
    return {
        default: { get, post, put, delete: deleteMethod, isAxiosError },
        get,
        post,
        put,
        delete: deleteMethod,
        isAxiosError,
    };
});

// Type-safe access to mocked axios methods
const mockedAxiosGet = axios.get as Mock;
const mockedAxiosPost = axios.post as Mock;
const mockedAxiosPut = axios.put as Mock;
const mockedAxiosDelete = axios.delete as Mock;

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: vi.fn(),
    },
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

describe('SetupIgsnLandingPageModal', () => {
    const mockResource = {
        id: 456,
        doi: 'IEFGZ0001',
        title: 'Rock Sample Core XYZ',
    };

    const mockExistingConfig: LandingPageConfig = {
        id: 1,
        resource_id: 456,
        template: 'default_gfz_igsn',
        ftp_url: null, // No FTP URL for IGSN
        status: 'draft',
        preview_token: 'test-preview-token-for-vitest-unit-tests-only-not-a-real-secret',
        view_count: 5,
        created_at: '2025-01-01T00:00:00Z',
        updated_at: '2025-01-02T00:00:00Z',
        public_url: 'http://localhost/draft-456/rock-sample-core-xyz',
        preview_url: 'http://localhost/draft-456/rock-sample-core-xyz?preview=token',
        contact_url: 'http://localhost/draft-456/rock-sample-core-xyz/contact',
    };

    const mockOnClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Rendering', () => {
        it('renders modal when open', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
                expect(screen.getByText(/Setup IGSN Landing Page/i)).toBeInTheDocument();
            });
        });

        it('does not render when closed', () => {
            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={false} onClose={mockOnClose} />);

            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });

        it('displays IGSN title in description', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByText(/Rock Sample Core XYZ/i)).toBeInTheDocument();
                expect(screen.getByText(/physical sample/i)).toBeInTheDocument();
            });
        });

        it('uses FlaskConical icon in title', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                // FlaskConical icon should be present (checking for SVG element)
                const title = screen.getByText(/Setup IGSN Landing Page/i);
                expect(title.closest('div')?.querySelector('svg')).toBeInTheDocument();
            });
        });
    });

    describe('IGSN-specific Form Fields', () => {
        it('renders template selection', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByLabelText(/Landing Page Template/i)).toBeInTheDocument();
            });
        });

        it('does NOT render FTP URL field', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // FTP URL field should not exist in IGSN modal
            expect(screen.queryByLabelText(/Download URL \(FTP\)/i)).not.toBeInTheDocument();
        });

        it('renders publish toggle', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByLabelText(/Publish Landing Page/i)).toBeInTheDocument();
            });
        });

        it('shows default IGSN template value', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByText(/Default GFZ IGSN Template/i)).toBeInTheDocument();
            });
        });
    });

    describe('API Integration', () => {
        it('fetches existing config on mount', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(axios.get).toHaveBeenCalledWith(expect.stringContaining(`/resources/${mockResource.id}/landing-page`));
            });
        });

        it('creates new landing page without ftp_url', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    landing_page: mockExistingConfig,
                },
            });
            mockedAxiosDelete.mockResolvedValue({ data: {} });

            const user = userEvent.setup();

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Click create button
            const createButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(createButton);

            await waitFor(() => {
                expect(axios.post).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        status: 'draft',
                    }),
                );
            });

            // Verify ftp_url is NOT in the payload
            const postCall = mockedAxiosPost.mock.calls[0];
            expect(postCall[1]).not.toHaveProperty('ftp_url');
        });

        it('updates existing landing page', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });
            mockedAxiosPut.mockResolvedValue({
                data: {
                    landing_page: mockExistingConfig,
                },
            });
            mockedAxiosDelete.mockResolvedValue({ data: {} });

            const user = userEvent.setup();

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Click update button
            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                    }),
                );
            });
        });
    });

    describe('Preview Functionality', () => {
        it('opens preview without ftp_url in payload', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPost.mockResolvedValue({
                data: { preview_url: '/resources/456/landing-page/preview' },
            });

            // Mock window.open
            const mockWindowOpen = vi.fn();
            vi.stubGlobal('open', mockWindowOpen);

            const user = userEvent.setup();

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Click preview button (exact match to avoid "Create Preview")
            const previewButton = screen.getByRole('button', { name: /^Preview$/i });
            await user.click(previewButton);

            await waitFor(() => {
                expect(axios.post).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page/preview`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                    }),
                );
            });

            // Verify ftp_url is NOT in the payload
            const postCall = mockedAxiosPost.mock.calls[0];
            expect(postCall[1]).not.toHaveProperty('ftp_url');

            vi.unstubAllGlobals();
        });
    });

    describe('Draft Removal', () => {
        it('shows remove preview button for draft landing pages', async () => {
            mockedAxiosGet.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'draft',
                    },
                },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('button', { name: /Remove Preview/i })).toBeInTheDocument();
            });
        });

        it('hides remove preview button for published landing pages', async () => {
            mockedAxiosGet.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'published',
                    },
                },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            expect(screen.queryByRole('button', { name: /Remove Preview/i })).not.toBeInTheDocument();
        });
    });

    describe('Published State', () => {
        it('disables publish toggle when already published', async () => {
            mockedAxiosGet.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'published',
                    },
                },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                const publishSwitch = screen.getByRole('switch', { name: /Publish Landing Page/i });
                expect(publishSwitch).toBeDisabled();
            });
        });

        it('shows public URL for published landing pages', async () => {
            mockedAxiosGet.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'published',
                        public_url: 'http://localhost/10.5880/IGSN.001/rock-sample',
                    },
                },
            });

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByText(/Public URL/i)).toBeInTheDocument();
            });
        });
    });

    describe('Unsaved Changes Warning', () => {
        it('shows unsaved changes warning when template is changed', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            const user = userEvent.setup();

            render(<SetupIgsnLandingPageModal resource={mockResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Open template dropdown and select different template
            const templateSelect = screen.getByRole('combobox');
            await user.click(templateSelect);

            // Select the default_gfz template (which should also be available for PhysicalObjects)
            const defaultOption = screen.getByText('Default GFZ Data Services');
            await user.click(defaultOption);

            await waitFor(() => {
                expect(screen.getByText(/You have unsaved changes/i)).toBeInTheDocument();
            });
        });
    });

    describe('Long Title Layout (Issue #670)', () => {
        // Regression tests for issue #670 applied to the IGSN modal variant.
        // Physical sample descriptions can also be long; the modal must not
        // overflow horizontally, footer buttons must stay visible, and only
        // the middle zone should scroll.

        const longTitle = 'Rock sample core collected from borehole '.repeat(20).trim();
        const longTitleResource = { id: 888, doi: 'IEFGZ0999', title: longTitle };

        beforeEach(() => {
            mockedAxiosGet.mockRejectedValue({ isAxiosError: true, response: { status: 404 } });
        });

        it('renders the full long title inside the title attribute for tooltips', async () => {
            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-igsn-lp-modal-resource-title');
            expect(titleEl).toHaveAttribute('title', longTitle);
            expect(titleEl).toHaveTextContent(longTitle);
        });

        it('applies line-clamp and word-wrap classes to the long title', async () => {
            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-igsn-lp-modal-resource-title');
            // Tailwind v4 emits `.wrap-break-word { overflow-wrap: break-word }`
            // (the v4 replacement for v3's `break-words`). Assert the exact
            // utility so a broken/missing class cannot hide an overflow regression.
            expect(titleEl.className).toContain('line-clamp-2');
            expect(titleEl.className).toContain('wrap-break-word');
        });

        it('falls back to "IGSN #<id>" when title is missing and still exposes it via title attribute', async () => {
            render(<SetupIgsnLandingPageModal resource={{ id: 555 }} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-igsn-lp-modal-resource-title');
            expect(titleEl).toHaveAttribute('title', 'IGSN #555');
            expect(titleEl).toHaveTextContent('IGSN #555');
        });

        it('moves overflow-y-auto from the dialog content onto the scroll body', async () => {
            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const content = await screen.findByTestId('setup-igsn-lp-modal-content');
            const scrollArea = await screen.findByTestId('setup-igsn-lp-modal-scroll-area');

            expect(content.className).not.toContain('overflow-y-auto');
            expect(content.className).toContain('overflow-hidden');
            expect(content.className).toContain('flex');
            expect(content.className).toContain('flex-col');

            expect(scrollArea.className).toContain('overflow-y-auto');
            expect(scrollArea.className).toContain('flex-1');
            expect(scrollArea.className).toContain('min-h-0');
        });

        it('renders a sticky footer with wrap + border that never shrinks', async () => {
            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const footer = await screen.findByTestId('setup-igsn-lp-modal-footer');
            expect(footer.className).toContain('shrink-0');
            expect(footer.className).toContain('flex-wrap');
            expect(footer.className).toContain('border-t');
        });

        it('keeps all primary footer buttons visible even with an extremely long title', async () => {
            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const footer = screen.getByTestId('setup-igsn-lp-modal-footer');
            expect(footer).toContainElement(screen.getByRole('button', { name: /^Preview$/i }));
            expect(footer).toContainElement(screen.getByRole('button', { name: /^Cancel$/i }));
            expect(footer).toContainElement(
                screen.getByRole('button', { name: /^(Create Preview|Create & Publish|Update|Publish)$/i }),
            );
        });

        it('shows the Loading state inside the scrollable zone (footer still rendered)', () => {
            mockedAxiosGet.mockImplementation(() => new Promise(() => {}));

            render(<SetupIgsnLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const scrollArea = screen.getByTestId('setup-igsn-lp-modal-scroll-area');
            expect(scrollArea).toHaveTextContent(/Loading configuration/i);

            expect(screen.getByTestId('setup-igsn-lp-modal-footer')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /^Cancel$/i })).toBeInTheDocument();
        });

        it('still applies the layout classes when a short title is used', async () => {
            render(
                <SetupIgsnLandingPageModal
                    resource={{ id: 1, title: 'Short sample' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const titleEl = await screen.findByTestId('setup-igsn-lp-modal-resource-title');
            expect(titleEl).toHaveAttribute('title', 'Short sample');
            expect(titleEl.className).toContain('line-clamp-2');
        });
    });
});
