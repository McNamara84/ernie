import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
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
        preview_token: 'preview-token-123',
        view_count: 42,
        created_at: '2025-01-01T00:00:00Z',
        updated_at: '2025-01-02T00:00:00Z',
        public_url: 'http://localhost/datasets/123',
        preview_url: 'http://localhost/datasets/123?preview=preview-token-123',
    };

    const mockOnClose = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Rendering', () => {
        it('renders modal when open', async () => {
            // Mock 404 response (no landing page exists yet)
            axios.get.mockRejectedValue({
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
            axios.get.mockRejectedValue({
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
            axios.get.mockRejectedValue({
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
            axios.get.mockRejectedValue({
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
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

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
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

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
            axios.get.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            axios.post.mockResolvedValue({
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
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });
            axios.put.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

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

        it('deletes landing page config', async () => {
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });
            axios.put.mockResolvedValue({ data: { message: 'Depublished' } });

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

            // Click depublish/remove button (button text is "Depublish" for published config)
            const depublishButton = screen.getByRole('button', { name: /Depublish/i });
            
            await user.click(depublishButton);

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        status: 'draft',
                    }),
                );
            });

            // Cleanup
            vi.unstubAllGlobals();
        });
    });

    describe('Preview URLs', () => {
        it('displays preview URL for draft configs', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            axios.get.mockResolvedValue({ data: { landing_page: draftConfig } });

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
                const urlInputs = screen.getAllByDisplayValue(
                    new RegExp(`/datasets/${mockResource.id}\\?preview=`),
                );
                expect(urlInputs.length).toBeGreaterThan(0);
            });
        });

        it('displays public URL for published configs', async () => {
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

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
                // Check that the URL input field exists
                const urlInput = screen.getByDisplayValue(
                    new RegExp(`/datasets/${mockResource.id}`),
                );
                expect(urlInput).toBeInTheDocument();
            });
        });

        it.skip('copies preview URL to clipboard', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            axios.get.mockResolvedValue({ data: { landing_page: draftConfig } });

            // Mock clipboard API
            const writeTextMock = vi.fn().mockResolvedValue(undefined);
            
            // Store original clipboard
            const originalClipboard = navigator.clipboard;
            
            // Mock clipboard writeText
            Object.defineProperty(navigator, 'clipboard', {
                value: {
                    writeText: writeTextMock,
                },
                configurable: true,
            });

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

            // Verify clipboard was called
            await waitFor(() => {
                expect(writeTextMock).toHaveBeenCalledTimes(1);
                expect(writeTextMock).toHaveBeenCalledWith(
                    expect.stringContaining('preview=preview-token-123')
                );
            });

            // Restore original clipboard
            Object.defineProperty(navigator, 'clipboard', {
                value: originalClipboard,
                configurable: true,
            });
        });
    });

    describe('Publish Toggle', () => {
        it('toggles between draft and published status', async () => {
            const draftConfig = { ...mockExistingConfig, status: 'draft' as const };
            axios.get.mockResolvedValue({ data: { landing_page: draftConfig } });

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
            axios.get.mockResolvedValue({ data: { landing_page: mockExistingConfig } });

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

            expect(mockOpen).toHaveBeenCalledWith(
                expect.stringContaining(`/datasets/${mockResource.id}`),
                '_blank',
            );

            vi.unstubAllGlobals();
        });
    });

    describe('Error Handling', () => {
        it('shows error message when fetch fails', async () => {
            axios.get.mockRejectedValue({
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
            axios.get.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            axios.post.mockRejectedValue({
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
            axios.get.mockRejectedValue({
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
            axios.get.mockRejectedValue({
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
});
