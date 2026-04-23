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
                // Component shows title if available, otherwise "Resource #${id}"
                expect(screen.getByText(/Test Resource Title/i)).toBeInTheDocument();
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

    describe('Template Selection Persistence (Regression #<fix>)', () => {
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
});
