import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SetupLandingPageModal from '@/components/landing-pages/modals/SetupLandingPageModal';
import type { LandingPageConfig } from '@/types/landing-page';

// Mock dependencies
vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    const put = vi.fn();
    const deleteMethod = vi.fn();
    const isAxiosError = vi.fn((value: unknown): value is { isAxiosError: true } => {
        return (
            typeof value === 'object' &&
            value !== null &&
            (value as { isAxiosError?: boolean }).isAxiosError === true
        );
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

describe('SetupLandingPageModal', () => {
    const mockResource = {
        id: 123,
        doi: '10.5880/GFZ.TEST.2025.001',
        title: 'Test Resource Title',
    };

    const mockExistingConfig: LandingPageConfig = {
        id: 1,
        resource_id: 123,
        template: 'default_gfz',
        ftp_url: 'https://datapub.gfz-potsdam.de/download/test-data',
        status: 'published',
        // Preview tokens are 64-character hex strings (32 bytes from random_bytes)
        preview_token: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', // ggignore
        view_count: 42,
        created_at: '2025-01-01T00:00:00Z',
        updated_at: '2025-01-02T00:00:00Z',
        public_url: 'http://localhost/10.5880/GFZ.TEST.2025.001/test-resource-title',
        preview_url: 'http://localhost/10.5880/GFZ.TEST.2025.001/test-resource-title?preview=a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        contact_url: 'http://localhost/10.5880/GFZ.TEST.2025.001/test-resource-title/contact',
    };

    const mockOnClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Rendering', () => {
        it('renders modal when open', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
                expect(screen.getByText(/Setup Landing Page/i)).toBeInTheDocument();
            });
        });

        it('does not render when closed', () => {
            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={false}
                    onClose={mockOnClose}
                />,
            );

            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });

        it('displays resource information in description', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                // Component shows title if available, otherwise "Resource #${id}".
                // Scope to the title testid: Radix Tooltip adds a VisuallyHidden
                // accessibility node that also contains the title text, so a
                // plain `getByText` would match more than one element.
                expect(screen.getByTestId('setup-lp-modal-resource-title')).toHaveTextContent(
                    'Test Resource Title',
                );
            });
        });
    });

    describe('Form Fields', () => {
        it('renders all form fields', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByLabelText(/Landing Page Template/i)).toBeInTheDocument();
                expect(screen.getByLabelText(/Download URL \(FTP\)/i)).toBeInTheDocument();
                expect(screen.getByLabelText(/Publish Landing Page/i)).toBeInTheDocument();
            });
        });

        it('shows default template value', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByText(/Default GFZ Data Services/i)).toBeInTheDocument();
            });
        });

        it('loads existing configuration', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                const ftpInput = screen.getByLabelText(/Download URL \(FTP\)/i) as HTMLInputElement;
                expect(ftpInput.value).toBe(mockExistingConfig.ftp_url);
            });
        });
    });

    describe('API Integration', () => {
        it('fetches existing config on mount', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(axios.get).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                );
            });
        });

        it('creates new landing page config', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'draft',
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Fill FTP URL (use full label text)
            const ftpInput = screen.getByLabelText(/Download URL \(FTP\)/i);
            await user.clear(ftpInput);
            await user.type(ftpInput, 'https://datapub.gfz-potsdam.de/download/new-data');

            // Submit (creates preview in draft mode by default)
            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            await waitFor(() => {
                expect(axios.post).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz',
                        ftp_url: 'https://datapub.gfz-potsdam.de/download/new-data',
                        status: 'draft',
                    }),
                );
            });
        });

        it('updates existing landing page config', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });
            mockedAxiosPut.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Change FTP URL (use full label text)
            const ftpInput = screen.getByLabelText(/Download URL \(FTP\)/i);
            await user.clear(ftpInput);
            await user.type(ftpInput, 'https://datapub.gfz-potsdam.de/download/updated-data');

            // Submit
            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        ftp_url: 'https://datapub.gfz-potsdam.de/download/updated-data',
                    }),
                );
            });
        });

        it('removes draft landing page preview', async () => {
            // Use a draft config (not published)
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: draftConfig } });
            mockedAxiosDelete.mockResolvedValue({ data: { message: 'Landing page deleted successfully' } });

            // Mock window.confirm to return true
            vi.stubGlobal('confirm', vi.fn(() => true));

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Click remove preview button (only available for draft configs)
            const removePreviewButton = screen.getByRole('button', { name: /Remove Preview/i });

            await user.click(removePreviewButton);

            await waitFor(() => {
                expect(axios.delete).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                );
            });

            // Cleanup
            vi.unstubAllGlobals();
        });

        it('does not show remove button for published landing pages', async () => {
            // Published landing pages cannot be depublished because DOIs are persistent
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Verify no Depublish or Remove Preview button is shown for published config
            expect(screen.queryByRole('button', { name: /Depublish/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Remove Preview/i })).not.toBeInTheDocument();
        });

        it('disables publish toggle for already published landing pages', async () => {
            // Published landing pages cannot be unpublished because DOIs are persistent
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // The publish switch should be disabled for published configs
            const publishSwitch = screen.getByRole('switch');
            expect(publishSwitch).toBeDisabled();

            // Check for DOI persistence message
            expect(screen.getByText(/DOIs are persistent/i)).toBeInTheDocument();
        });
    });

    describe('Preview URLs', () => {
        it('displays preview URL for draft configs', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: draftConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                // Check for the label
                expect(screen.getByText(/Preview URL/i)).toBeInTheDocument();
                // Check that the URL input field exists and contains the preview URL
                // Preview tokens are 64-character hex strings, validate format not specific value
                const urlInputs = screen.getAllByDisplayValue(
                    new RegExp(`\\?preview=[a-f0-9]{64}`),
                );
                expect(urlInputs.length).toBeGreaterThan(0);
            });
        });

        it('displays public URL for published configs', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                // Check for the label
                expect(screen.getByText(/Public URL/i)).toBeInTheDocument();
                // Check that the URL input field exists with semantic URL (DOI-based)
                const urlInput = screen.getByDisplayValue(
                    new RegExp(`10\\.5880/GFZ\\.TEST\\.2025\\.001/test-resource-title`),
                );
                expect(urlInput).toBeInTheDocument();
            });
        });

        it('copies preview URL to clipboard', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: draftConfig } });

            // Spy on navigator.clipboard.writeText
            const writeTextSpy = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined);

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // Wait for modal and config to load
            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Wait for the preview URL section to appear
            await waitFor(() => {
                expect(screen.getByText(/Preview URL \(Draft Mode\)/i)).toBeInTheDocument();
            });

            // Find and click the copy button
            const copyButton = screen.getByTitle('Copy preview URL');
            
            await user.click(copyButton);
            
            // Small delay to allow async operations
            await new Promise(resolve => setTimeout(resolve, 100));

            // Verify clipboard was called with the preview URL containing a valid token format
            expect(writeTextSpy).toHaveBeenCalledTimes(1);
            expect(writeTextSpy).toHaveBeenCalledWith(
                expect.stringMatching(/preview=[a-f0-9]{64}/)
            );

            writeTextSpy.mockRestore();
        });
    });

    describe('Publish Toggle', () => {
        it('toggles between draft and published status', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: draftConfig } });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Should show as draft initially - switch should NOT be checked
            const publishSwitch = screen.getByRole('switch', { name: /Publish Landing Page/i });
            expect(publishSwitch).not.toBeChecked();

            // Toggle to published
            await user.click(publishSwitch);
            expect(publishSwitch).toBeChecked();
        });
    });

    describe('Preview Button', () => {
        it('opens preview in new tab', async () => {
            mockedAxiosGet.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

            // Mock window.open
            const mockOpen = vi.fn();
            vi.stubGlobal('open', mockOpen);

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Get the Preview button with Eye icon (not the "Remove Preview" destructive button)
            const previewButtons = screen.getAllByRole('button', { name: /Preview/i });
            const previewButton = previewButtons.find(
                (btn) => btn.className.includes('outline') || btn.textContent?.trim() === 'Preview'
            );
            expect(previewButton).toBeDefined();
            await user.click(previewButton!);

            // Should open the preview_url from the config (semantic URL with preview token)
            expect(mockOpen).toHaveBeenCalledWith(
                expect.stringContaining('10.5880/GFZ.TEST.2025.001/test-resource-title'),
                '_blank',
                'noopener,noreferrer',
            );

            vi.unstubAllGlobals();
        });
    });

    describe('Error Handling', () => {
        it('shows error message when fetch fails', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: {
                    status: 404,
                    data: { message: 'Not found' },
                },
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // Should still render but in create mode
            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
                expect(screen.getByRole('button', { name: /Create Preview/i })).toBeInTheDocument();
            });
        });

        it('shows error message when save fails', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPost.mockRejectedValue({
                isAxiosError: true,
                response: {
                    data: { message: 'Validation failed' },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            // Error should be handled (toast notification in actual app)
            await waitFor(() => {
                expect(axios.post).toHaveBeenCalled();
            });
        });
    });

    describe('Close Behavior', () => {
        it('calls onClose when close button clicked', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const closeButton = screen.getByRole('button', { name: /close/i });
            await user.click(closeButton);

            expect(mockOnClose).toHaveBeenCalled();
        });

        it('calls onClose when cancel button clicked', async () => {
            // Mock 404 response (no landing page exists yet)
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const cancelButton = screen.getByRole('button', { name: /Cancel/i });
            await user.click(cancelButton);

            expect(mockOnClose).toHaveBeenCalled();
        });
    });

    describe('Custom Templates', () => {
        it('loads custom templates from API on open', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 1,
                                    name: 'Default GFZ Data Services',
                                    slug: 'default_gfz',
                                    is_default: true,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                                {
                                    id: 2,
                                    name: 'Custom Geophysics',
                                    slug: 'custom-geophysics',
                                    is_default: false,
                                    logo_url: 'http://localhost/storage/logos/geo.png',
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                            ],
                        },
                    });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(mockedAxiosGet).toHaveBeenCalledWith(
                    expect.stringContaining('/api/landing-page-templates'),
                );
            });
        });

        it('handles custom template API failure gracefully', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.reject(new Error('API error'));
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // Should still render without crashing
            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            consoleSpy.mockRestore();
        });

        it('passes landing_page_template_id when saving with existing config prop', async () => {
            const configWithTemplate: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                landing_page_template_id: 5,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: configWithTemplate } });
            });
            mockedAxiosPut.mockResolvedValue({ data: { landing_page: configWithTemplate } });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                    existingConfig={configWithTemplate}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(mockedAxiosPut).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        landing_page_template_id: 5,
                    }),
                );
            });
        });

        it('renders custom templates in dropdown when loaded', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 1,
                                    name: 'Default GFZ Data Services',
                                    slug: 'default_gfz',
                                    is_default: true,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                                {
                                    id: 2,
                                    name: 'Custom Geophysics',
                                    slug: 'custom-geophysics',
                                    is_default: false,
                                    logo_url: 'http://localhost/storage/logos/geo.png',
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                            ],
                        },
                    });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Open the template select dropdown
            const templateSelect = screen.getByLabelText(/Landing Page Template/i);
            await user.click(templateSelect);

            // Custom templates section should be visible
            await waitFor(() => {
                expect(screen.getByText('Custom Templates')).toBeInTheDocument();
                expect(screen.getByText('Custom Geophysics')).toBeInTheDocument();
            });
        });

        it('selects a custom template and sets landing_page_template_id', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 1,
                                    name: 'Default GFZ Data Services',
                                    slug: 'default_gfz',
                                    is_default: true,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                                {
                                    id: 3,
                                    name: 'Custom Hydrology',
                                    slug: 'custom-hydrology',
                                    is_default: false,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                            ],
                        },
                    });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    message: 'Landing page created',
                    landing_page: { ...mockExistingConfig, status: 'draft', landing_page_template_id: 3 },
                    preview_url: '/preview',
                },
            });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Open dropdown and select a custom template
            const templateSelect = screen.getByLabelText(/Landing Page Template/i);
            await user.click(templateSelect);

            await waitFor(() => {
                expect(screen.getByText('Custom Hydrology')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Custom Hydrology'));

            // Save - should include the custom template ID
            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        landing_page_template_id: 3,
                    }),
                );
            });
        });

        it('resets landing_page_template_id when switching to built-in template', async () => {
            const configWithCustomTemplate: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                landing_page_template_id: 2,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 1,
                                    name: 'Default GFZ Data Services',
                                    slug: 'default_gfz',
                                    is_default: true,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                                {
                                    id: 2,
                                    name: 'Custom Geophysics',
                                    slug: 'custom-geophysics',
                                    is_default: false,
                                    logo_url: null,
                                    right_column_order: [],
                                    left_column_order: [],
                                },
                            ],
                        },
                    });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: configWithCustomTemplate } });
            });
            mockedAxiosPut.mockResolvedValue({ data: { landing_page: configWithCustomTemplate } });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                    existingConfig={configWithCustomTemplate}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Open the template select and choose "External Landing Page" (a built-in template)
            const templateSelect = screen.getByLabelText(/Landing Page Template/i);
            await user.click(templateSelect);

            await waitFor(() => {
                const externalOption = screen.getByText('External Landing Page');
                expect(externalOption).toBeInTheDocument();
                return externalOption;
            });

            await user.click(screen.getByText('External Landing Page'));

            // Save and verify landing_page_template_id is null (not custom)
            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(mockedAxiosPut).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        landing_page_template_id: null,
                    }),
                );
            });
        });

        it('calls onSuccess callback after successful save', async () => {
            const onSuccess = vi.fn();

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    message: 'Landing page created',
                    landing_page: { ...mockExistingConfig, status: 'draft' },
                    preview_url: '/preview',
                },
            });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                    onSuccess={onSuccess}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            await waitFor(() => {
                expect(onSuccess).toHaveBeenCalled();
            });
        });

        it('shows validation errors from server on save failure', async () => {
            const { toast } = await import('sonner');

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });
            mockedAxiosPost.mockRejectedValue({
                isAxiosError: true,
                response: {
                    data: {
                        errors: {
                            template: ['Invalid template'],
                            ftp_url: ['Invalid URL format'],
                        },
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Invalid template, Invalid URL format');
            });
        });

        it('handles non-404 fetch error with toast', async () => {
            const { toast } = await import('sonner');

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                // Non-404 error - should show toast
                return Promise.reject({
                    isAxiosError: true,
                    response: { status: 500, data: { message: 'Server error' } },
                });
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(toast.error).toHaveBeenCalledWith('Failed to load landing page configuration');
            });
        });

        it('prevents removal of published landing page with error toast', async () => {
            // Published config cannot be removed
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: mockExistingConfig } });
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Published landing pages should not have a Remove Preview button
            expect(screen.queryByRole('button', { name: /Remove Preview/i })).not.toBeInTheDocument();
        });
    });

    describe('Template Selection Persistence (Regression PR #674)', () => {
        // Regression tests for the bug where a custom landing page template was
        // not retained when the Setup Landing Page dialog was reopened: the
        // loadLandingPageConfig() path did not hydrate landing_page_template_id
        // into state, so the dropdown fell back to "default_gfz" and a subsequent
        // save overwrote the DB field with null.

        const customTemplatesResponse = {
            data: {
                templates: [
                    {
                        id: 1,
                        name: 'Default GFZ Data Services',
                        slug: 'default_gfz',
                        is_default: true,
                        logo_url: null,
                        right_column_order: [],
                        left_column_order: [],
                    },
                    {
                        id: 42,
                        name: 'My Custom Template',
                        slug: 'my-custom-template',
                        is_default: false,
                        logo_url: null,
                        right_column_order: [],
                        left_column_order: [],
                    },
                ],
            },
        };

        it('restores custom template selection when loading config from server (no existingConfig prop)', async () => {
            const serverConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                template: 'default_gfz',
                landing_page_template_id: 42,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve(customTemplatesResponse);
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: serverConfig } });
            });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // The select trigger should display the custom template name, not
            // the fallback "Default GFZ Data Services".
            await waitFor(() => {
                const trigger = screen.getByLabelText(/Landing Page Template/i);
                expect(trigger).toHaveTextContent('My Custom Template');
            });
        });

        it('retains custom template selection after closing and reopening the dialog', async () => {
            // Reproduces the exact user-reported scenario: open dialog, close
            // it, reopen it — the saved custom template must still be shown
            // in the dropdown (not "Default GFZ Data Services"). Before the
            // fix, reopening hydrated all fields except landing_page_template_id,
            // so the select fell back to the built-in default value.
            const serverConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                template: 'default_gfz',
                landing_page_template_id: 42,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve(customTemplatesResponse);
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: serverConfig } });
            });

            const { rerender } = render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // First open: custom template is shown.
            await waitFor(() => {
                expect(screen.getByLabelText(/Landing Page Template/i)).toHaveTextContent('My Custom Template');
            });

            // Close the dialog (state is reset in the component's useEffect
            // cleanup branch when isOpen transitions to false).
            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={false}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });

            // Reopen the dialog — this re-triggers loadLandingPageConfig().
            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            // After reopen, the custom template must still be selected.
            await waitFor(() => {
                expect(screen.getByLabelText(/Landing Page Template/i)).toHaveTextContent('My Custom Template');
            });
        });

        it('sends the previously saved landing_page_template_id when saving without re-selection', async () => {
            // This asserts the cascading symptom: if the custom template is NOT
            // hydrated into state, a plain Save (no re-selection) sends null
            // and silently resets the DB field. With the fix in place, the
            // original template id must round-trip back on save.
            const serverConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                template: 'default_gfz',
                landing_page_template_id: 42,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve(customTemplatesResponse);
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: serverConfig } });
            });
            mockedAxiosPut.mockResolvedValue({ data: { landing_page: serverConfig } });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            // Wait for the custom template to be reflected in the trigger.
            await waitFor(() => {
                const trigger = screen.getByLabelText(/Landing Page Template/i);
                expect(trigger).toHaveTextContent('My Custom Template');
            });

            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(mockedAxiosPut).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz',
                        landing_page_template_id: 42,
                    }),
                );
            });
        });

        it('resets landing_page_template_id to null when server returns null (default template)', async () => {
            // Regression guard: the defensive null-reset in the 404 branch and
            // the successful-load branch must keep the null path stable.
            const serverConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                template: 'default_gfz',
                landing_page_template_id: null,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve(customTemplatesResponse);
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.resolve({ data: { landing_page: serverConfig } });
            });
            mockedAxiosPut.mockResolvedValue({ data: { landing_page: serverConfig } });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                const trigger = screen.getByLabelText(/Landing Page Template/i);
                expect(trigger).toHaveTextContent('Default GFZ Data Services');
            });

            const updateButton = screen.getByRole('button', { name: /Update/i });
            await user.click(updateButton);

            await waitFor(() => {
                expect(mockedAxiosPut).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz',
                        landing_page_template_id: null,
                    }),
                );
            });
        });

        it('resets landing_page_template_id to null on 404 (no landing page exists)', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve(customTemplatesResponse);
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                return Promise.reject({
                    isAxiosError: true,
                    response: { status: 404 },
                });
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    message: 'Landing page created',
                    landing_page: {
                        ...mockExistingConfig,
                        status: 'draft',
                        landing_page_template_id: null,
                    },
                    preview_url: '/preview',
                },
            });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const saveButton = screen.getByRole('button', { name: /Create Preview/i });
            await user.click(saveButton);

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        landing_page_template_id: null,
                    }),
                );
            });
        });
    });

    describe('Long Title Layout (Issue #670)', () => {
        // Regression tests for issue #670: a very long resource title used to
        // break the modal layout (horizontal overflow, footer buttons cut off).
        // The fix introduces a three-zone flex layout (sticky header/footer,
        // scrollable body) and truncates the title to two lines. An accessible
        // shadcn Tooltip (keyboard-focusable, visible on hover/focus) exposes
        // the full string to sighted users; the full text is also present in
        // the element's text content for screen readers.

        const longTitle = 'A'.repeat(500);
        const longTitleResource = { id: 999, doi: '10.5880/GFZ.TEST.LONG', title: longTitle };

        beforeEach(() => {
            // URL-based mock so only the primary landing-page GET returns 404.
            // Returning empty lists for domain / template endpoints prevents
            // noisy console.error output and mirrors real production behavior
            // when a resource has no landing page yet.
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });
        });

        it('renders the full long title inside the element text content (accessible to screen readers)', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');
            expect(titleEl).toHaveTextContent(longTitle);
        });

        it('exposes the title via an accessible, focusable shadcn Tooltip trigger (not the native title attribute)', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');

            // Keyboard accessibility: the element must be focusable so keyboard
            // users can trigger the tooltip (the native `title` attribute is
            // not keyboard-accessible — that is the whole point of this fix).
            expect(titleEl).toHaveAttribute('tabindex', '0');

            // It must be wired up as a Radix/shadcn tooltip trigger …
            expect(titleEl).toHaveAttribute('data-slot', 'tooltip-trigger');

            // … and must NOT fall back to the inaccessible native tooltip.
            expect(titleEl).not.toHaveAttribute('title');
        });

        it('shows the full long title in the shadcn tooltip content on hover', async () => {
            const user = userEvent.setup();
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');
            await user.hover(titleEl);

            // Tooltip content is rendered in a portal. Wait for the tooltip
            // element to appear and verify it contains the full title.
            const tooltip = await screen.findByTestId('setup-lp-modal-resource-title-tooltip');
            expect(tooltip).toHaveTextContent(longTitle);
        });

        it('applies line-clamp and word-wrap classes to the long title', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');
            // Tailwind v4 emits `.wrap-break-word { overflow-wrap: break-word }`
            // (the v4 replacement for v3's `break-words`). Assert the exact
            // utility so a broken/missing class cannot hide an overflow regression.
            expect(titleEl.className).toContain('line-clamp-2');
            expect(titleEl.className).toContain('wrap-break-word');
        });

        it('falls back to "Resource #<id>" when title is missing and still exposes it via the accessible tooltip', async () => {
            render(
                <SetupLandingPageModal
                    resource={{ id: 777 }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');
            expect(titleEl).toHaveTextContent('Resource #777');
            expect(titleEl).toHaveAttribute('data-slot', 'tooltip-trigger');
            expect(titleEl).toHaveAttribute('tabindex', '0');
        });

        it('moves overflow-y-auto from the dialog content onto the scroll body', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const content = await screen.findByTestId('setup-lp-modal-content');
            const scrollArea = await screen.findByTestId('setup-lp-modal-scroll-area');

            // Scrolling happens inside the middle zone, not on the dialog itself.
            expect(content.className).not.toContain('overflow-y-auto');
            expect(content.className).toContain('overflow-hidden');
            expect(content.className).toContain('flex');
            expect(content.className).toContain('flex-col');

            expect(scrollArea.className).toContain('overflow-y-auto');
            expect(scrollArea.className).toContain('flex-1');
            expect(scrollArea.className).toContain('min-h-0');
        });

        it('renders a sticky footer with wrap + border that never shrinks', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const footer = await screen.findByTestId('setup-lp-modal-footer');
            expect(footer.className).toContain('shrink-0');
            expect(footer.className).toContain('flex-wrap');
            expect(footer.className).toContain('border-t');
        });

        it('keeps all primary footer buttons visible even with an extremely long title', async () => {
            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            const footer = screen.getByTestId('setup-lp-modal-footer');
            // The footer must always contain at least Preview, Cancel and the
            // primary save/publish button. Issue #670 reported these being
            // pushed off-screen on narrow viewports.
            expect(footer).toContainElement(screen.getByRole('button', { name: /^Preview$/i }));
            expect(footer).toContainElement(screen.getByRole('button', { name: /^Cancel$/i }));
            expect(footer).toContainElement(screen.getByRole('button', { name: /^(Create Preview|Create & Publish|Update|Publish)$/i }));
        });

        it('shows the Loading state inside the scrollable zone (footer still rendered)', () => {
            // Keep the initial GET pending so the modal stays in its loading state.
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                return new Promise(() => {});
            });

            render(<SetupLandingPageModal resource={longTitleResource} isOpen={true} onClose={mockOnClose} />);

            const scrollArea = screen.getByTestId('setup-lp-modal-scroll-area');
            expect(scrollArea).toHaveTextContent(/Loading configuration/i);

            // Footer (and its Cancel button) must stay reachable during loading.
            expect(screen.getByTestId('setup-lp-modal-footer')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /^Cancel$/i })).toBeInTheDocument();
        });

        it('applies the same accessible tooltip and layout classes for short titles', async () => {
            render(
                <SetupLandingPageModal
                    resource={{ id: 1, title: 'Short title' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const titleEl = await screen.findByTestId('setup-lp-modal-resource-title');
            // Short titles use the same accessible tooltip pattern for consistency.
            // They are not visually clamped (only two lines are ever clamped),
            // but the tooltip wiring and layout classes are applied uniformly.
            expect(titleEl).toHaveTextContent('Short title');
            expect(titleEl).toHaveAttribute('data-slot', 'tooltip-trigger');
            expect(titleEl).toHaveAttribute('tabindex', '0');
            expect(titleEl).not.toHaveAttribute('title');
            expect(titleEl.className).toContain('line-clamp-2');
            expect(titleEl.className).toContain('wrap-break-word');
        });
    });
});
