import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { toast } from 'sonner';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SetupLandingPageModal from '@/components/landing-pages/modals/SetupLandingPageModal';
import type { LandingPageConfig, LandingPageDownloadUrlSuggestions } from '@/types/landing-page';

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
const mockedToastError = toast.error as Mock;

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
    const mockDomains = [
        { id: 1, domain: 'https://example.org/' },
        { id: 2, domain: 'https://resources.example.org/' },
    ];

    const mockDownloadUrlSuggestions: LandingPageDownloadUrlSuggestions = {
        domains: [
            { value: 'https://datapub.gfz.de/', usage_count: 4 },
            { value: 'https://archive.gfz.de/', usage_count: 1 },
        ],
        urls: [
            { value: 'https://datapub.gfz.de/download/10.5880.DIGIS.E.2025.002-aYVBW', usage_count: 2 },
            { value: 'https://archive.gfz.de/files/supplement.pdf', usage_count: 1 },
        ],
    };

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
        window.sessionStorage.clear();
    });

    const mockModalGetRequests = ({
        landingPage = null,
        downloadSuggestions = mockDownloadUrlSuggestions,
        downloadSuggestionsError,
    }: {
        landingPage?: LandingPageConfig | null;
        downloadSuggestions?: LandingPageDownloadUrlSuggestions;
        downloadSuggestionsError?: unknown;
    } = {}) => {
        mockedAxiosGet.mockImplementation((url: string) => {
            if (url.includes(`/resources/${mockResource.id}/landing-page`)) {
                if (landingPage === null) {
                    return Promise.reject({
                        isAxiosError: true,
                        response: { status: 404 },
                    });
                }

                return Promise.resolve({ data: { landing_page: landingPage } });
            }

            if (url === '/api/landing-page-domains/list') {
                return Promise.resolve({ data: { domains: mockDomains } });
            }

            if (url === '/api/landing-page-templates') {
                return Promise.resolve({ data: { templates: [] } });
            }

            if (url === '/api/landing-page-download-url-suggestions') {
                if (downloadSuggestionsError) {
                    return Promise.reject(downloadSuggestionsError);
                }

                return Promise.resolve({ data: { suggestions: downloadSuggestions } });
            }

            return Promise.reject(new Error(`Unexpected GET request: ${url}`));
        });
    };

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
                expect(screen.getByLabelText(/^Download URL$/i)).toBeInTheDocument();
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

        it('treats the human-readable Physical Object name as an IGSN resource', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('combobox', { name: /landing page template/i })).toHaveTextContent('Default GFZ IGSN Template');
            });

            await user.click(screen.getByRole('combobox', { name: /landing page template/i }));

            const optionsList = screen.getByRole('listbox');
            expect(optionsList).toHaveTextContent('Default GFZ IGSN Template');
            expect(optionsList).toHaveTextContent('External Landing Page');
            expect(optionsList).not.toHaveTextContent('Default GFZ Data Services');
            expect(screen.queryByLabelText(/^Download URL$/i)).not.toBeInTheDocument();
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
                const ftpInput = screen.getByLabelText(/^Download URL$/i) as HTMLInputElement;
                expect(ftpInput.value).toBe(mockExistingConfig.ftp_url);
            });
        });

        it('shows grouped download url suggestions when the field receives focus', async () => {
            mockModalGetRequests();

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);
            await user.click(ftpInput);

            await waitFor(() => {
                expect(axios.get).toHaveBeenCalledWith('/api/landing-page-download-url-suggestions');
            });

            expect(screen.getByText('Suggested domains')).toBeInTheDocument();
            expect(screen.getByText('Previously used full URLs')).toBeInTheDocument();
            expect(screen.getByText('https://datapub.gfz.de/')).toBeInTheDocument();
            expect(screen.getByText('https://datapub.gfz.de/download/10.5880.DIGIS.E.2025.002-aYVBW')).toBeInTheDocument();
        });

        it('exposes the download url input as a combobox for assistive technology', async () => {
            mockModalGetRequests();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);

            expect(ftpInput).toHaveAttribute('role', 'combobox');
            expect(ftpInput).toHaveAttribute('aria-autocomplete', 'list');
        });

        it('filters suggestions and lets the user keep editing after choosing a domain', async () => {
            mockModalGetRequests();

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);

            await user.click(ftpInput);
            await user.type(ftpInput, 'archive');

            expect(screen.queryByText('https://datapub.gfz.de/')).not.toBeInTheDocument();
            expect(screen.getByText('https://archive.gfz.de/')).toBeInTheDocument();

            await user.clear(ftpInput);
            await user.click(screen.getByText('https://datapub.gfz.de/'));

            expect(ftpInput).toHaveValue('https://datapub.gfz.de/');

            await user.type(ftpInput, 'download/custom-file');

            expect(ftpInput).toHaveValue('https://datapub.gfz.de/download/custom-file');
        });

        it('inserts the full url when a full-url suggestion is selected', async () => {
            mockModalGetRequests();

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);

            await user.click(ftpInput);
            await user.click(screen.getByText('https://datapub.gfz.de/download/10.5880.DIGIS.E.2025.002-aYVBW'));

            expect(ftpInput).toHaveValue('https://datapub.gfz.de/download/10.5880.DIGIS.E.2025.002-aYVBW');
        });

        it('supports keyboard navigation and selection for download url suggestions', async () => {
            mockModalGetRequests();

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);

            await user.click(ftpInput);

            await waitFor(() => {
                expect(screen.getByRole('listbox')).toBeInTheDocument();
            });

            await user.keyboard('{ArrowDown}');

            expect(ftpInput).toHaveAttribute('aria-activedescendant', 'ftp-url-domain-suggestion-0');

            await user.keyboard('{Enter}');

            expect(ftpInput).toHaveValue('https://datapub.gfz.de/');
            expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        });

        it('does not fetch suggestions when imported files disable the ftp field', async () => {
            mockModalGetRequests({
                landingPage: {
                    ...mockExistingConfig,
                    files: [
                        {
                            id: 1,
                            url: 'https://legacy.gfz.de/download/file-one.zip',
                            position: 0,
                        },
                    ],
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    existingConfig={{
                        ...mockExistingConfig,
                        files: [
                            {
                                id: 1,
                                url: 'https://legacy.gfz.de/download/file-one.zip',
                                position: 0,
                            },
                        ],
                    }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);
            expect(ftpInput).toBeDisabled();

            await user.click(ftpInput);

            expect(axios.get).not.toHaveBeenCalledWith('/api/landing-page-download-url-suggestions');
        });

        it('keeps the modal usable when loading download url suggestions fails', async () => {
            mockModalGetRequests({
                downloadSuggestionsError: new Error('Network error'),
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);
            await user.click(ftpInput);

            await waitFor(() => {
                expect(axios.get).toHaveBeenCalledWith('/api/landing-page-download-url-suggestions');
            });

            expect(mockedToastError).not.toHaveBeenCalled();
            expect(screen.getByText('No matching suggestions.')).toBeInTheDocument();

            await user.type(ftpInput, 'https://manual.example.org/file.zip');
            expect(ftpInput).toHaveValue('https://manual.example.org/file.zip');
        });

        it('normalizes a legacy Physical Object config passed via props to the IGSN template', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPut.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        template: 'default_gfz_igsn',
                        ftp_url: null,
                        landing_page_template_id: null,
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    existingConfig={{ ...mockExistingConfig, template: 'default_gfz', landing_page_template_id: null }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('combobox', { name: /landing page template/i })).toHaveTextContent('Default GFZ IGSN Template');
            });

            expect(screen.queryByLabelText(/^Download URL$/i)).not.toBeInTheDocument();

            await user.click(screen.getByRole('button', { name: /Update/i }));

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        landing_page_template_id: null,
                    }),
                );
            });

            expect(mockedAxiosPut.mock.calls.at(-1)?.[1]).not.toHaveProperty('ftp_url');
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

        it('normalizes a legacy Physical Object config loaded from the server before update', async () => {
            const legacyConfig = {
                ...mockExistingConfig,
                template: 'default_gfz',
                landing_page_template_id: null,
                status: 'draft' as const,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes(`/resources/${mockResource.id}/landing-page`)) {
                    return Promise.resolve({ data: { landing_page: legacyConfig } });
                }

                return Promise.reject({
                    isAxiosError: true,
                    response: { status: 404 },
                });
            });
            mockedAxiosPut.mockResolvedValue({
                data: {
                    landing_page: {
                        ...legacyConfig,
                        template: 'default_gfz_igsn',
                        ftp_url: null,
                        landing_page_template_id: null,
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('combobox', { name: /landing page template/i })).toHaveTextContent('Default GFZ IGSN Template');
            });

            await user.click(screen.getByRole('button', { name: /Update/i }));

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        landing_page_template_id: null,
                    }),
                );
            });

            expect(mockedAxiosPut.mock.calls.at(-1)?.[1]).not.toHaveProperty('ftp_url');
        });

        it('preserves a matching igsn custom template when a legacy Physical Object config is normalized', async () => {
            const legacyConfig = {
                ...mockExistingConfig,
                template: 'default_gfz',
                landing_page_template_id: 9,
                landing_page_template: {
                    id: 9,
                    name: 'Legacy Sample Layout',
                    slug: 'legacy-sample-layout',
                    is_default: false,
                    template_type: 'igsn' as const,
                    logo_path: null,
                    logo_url: null,
                    right_column_order: [],
                    left_column_order: [],
                },
                status: 'draft' as const,
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [legacyConfig.landing_page_template],
                        },
                    });
                }

                if (url.includes(`/resources/${mockResource.id}/landing-page`)) {
                    return Promise.resolve({ data: { landing_page: legacyConfig } });
                }

                return Promise.reject({
                    isAxiosError: true,
                    response: { status: 404 },
                });
            });
            mockedAxiosPut.mockResolvedValue({
                data: {
                    landing_page: {
                        ...legacyConfig,
                        template: 'default_gfz_igsn',
                        ftp_url: null,
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByLabelText(/Landing Page Template/i)).toHaveTextContent('Legacy Sample Layout');
            });

            await user.click(screen.getByRole('button', { name: /Update/i }));

            await waitFor(() => {
                expect(axios.put).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        landing_page_template_id: 9,
                    }),
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
            const ftpInput = screen.getByLabelText(/^Download URL$/i);
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

        it('clears ftp_url when a Physical Object is saved through the shared modal', async () => {
            mockedAxiosGet.mockRejectedValue({
                isAxiosError: true,
                response: { status: 404 },
            });
            mockedAxiosPost.mockResolvedValue({
                data: {
                    landing_page: {
                        ...mockExistingConfig,
                        template: 'default_gfz_igsn',
                        ftp_url: null,
                        status: 'draft',
                    },
                },
            });

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            await user.click(screen.getByRole('button', { name: /Create Preview/i }));

            await waitFor(() => {
                expect(axios.post).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                    }),
                );
            });

            expect(mockedAxiosPost.mock.calls.at(-1)?.[1]).not.toHaveProperty('ftp_url');
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
            const ftpInput = screen.getByLabelText(/^Download URL$/i);
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

        it('does not treat a normalized legacy Physical Object config as unsaved for preview generation', async () => {
            const legacyConfig: LandingPageConfig = {
                ...mockExistingConfig,
                template: 'default_gfz',
                status: 'draft',
                preview_url: 'http://localhost/legacy-preview',
            };

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }

                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }

                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });

            const mockWindowOpen = vi.fn();
            vi.stubGlobal('open', mockWindowOpen);

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    existingConfig={legacyConfig}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('combobox', { name: /landing page template/i })).toHaveTextContent('Default GFZ IGSN Template');
            });

            expect(screen.queryByText(/You have unsaved changes/i)).not.toBeInTheDocument();

            const previewButtons = screen.getAllByRole('button', { name: /Preview/i });
            const previewButton = previewButtons.find(
                (button) => button.className.includes('outline') || button.textContent?.trim() === 'Preview'
            );

            expect(previewButton).toBeDefined();
            await user.click(previewButton!);

            expect(mockedAxiosPost).not.toHaveBeenCalled();
            expect(mockedToastError).not.toHaveBeenCalled();

            vi.unstubAllGlobals();
        });

        it('shows the resulting external URL using the normalized previewable path', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: mockDomains } });
                }

                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
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

            await user.click(screen.getByLabelText(/Landing Page Template/i));
            await user.click(screen.getByText('External Landing Page'));
            await user.click(screen.getByLabelText(/Domain/i));
            await user.click(screen.getByText('https://example.org/'));
            await user.type(screen.getByLabelText(/Path/i), '  /dataset/preview  ');

            expect(screen.getByText('Resulting URL')).toBeInTheDocument();
            expect(screen.getByText('https://example.org/dataset/preview')).toBeInTheDocument();

            await user.clear(screen.getByLabelText(/Path/i));
            await user.type(screen.getByLabelText(/Path/i), '   ');

            expect(screen.queryByText('Resulting URL')).not.toBeInTheDocument();
            expect(screen.queryByText('https://example.org/dataset/preview')).not.toBeInTheDocument();
        });

        it('does not fall back to the saved external URL when unsaved edits clear the external path', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: mockDomains } });
                }

                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }

                return Promise.reject({ isAxiosError: true, response: { status: 404 } });
            });

            const mockWindowOpen = vi.fn();
            vi.stubGlobal('open', mockWindowOpen);

            const user = userEvent.setup();
            const existingExternalConfig: LandingPageConfig = {
                ...mockExistingConfig,
                template: 'external',
                ftp_url: null,
                external_domain_id: 1,
                external_path: '/saved-resource',
                external_url: 'https://example.org/saved-resource',
            };

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    existingConfig={existingExternalConfig}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByLabelText(/Path/i)).toHaveValue('/saved-resource');
            });

            await user.clear(screen.getByLabelText(/Path/i));
            await user.click(screen.getByRole('button', { name: /^Preview$/i }));

            expect(mockWindowOpen).not.toHaveBeenCalled();
            expect(mockedToastError).toHaveBeenCalledWith('Please select a domain and enter a path to preview the external URL.');

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

        it('retains unsaved download url and additional links after closing and reopening', async () => {
            mockModalGetRequests({ landingPage: null });

            const user = userEvent.setup();
            const { rerender } = render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);
            await user.clear(ftpInput);
            await user.type(ftpInput, 'https://downloads.example.org/draft-file.zip');

            await user.click(screen.getByRole('button', { name: /add link/i }));
            await user.type(screen.getByPlaceholderText(/display text/i), 'Project Website');
            await user.type(screen.getByPlaceholderText('https://...'), 'https://example.org/project');

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={false}
                    onClose={mockOnClose}
                />,
            );

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const reopenedFtpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(reopenedFtpInput.value).toBe('https://downloads.example.org/draft-file.zip');
            expect(screen.getByDisplayValue('Project Website')).toBeInTheDocument();
            expect(screen.getByDisplayValue('https://example.org/project')).toBeInTheDocument();
        });

        it('clears persisted unsaved values after a successful save', async () => {
            mockModalGetRequests({ landingPage: null });

            const savedConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft',
                ftp_url: 'https://saved.example.org/final-file.zip',
                links: [],
            };

            mockedAxiosPost.mockResolvedValue({
                data: {
                    message: 'Landing page saved',
                    landing_page: savedConfig,
                    preview_url: savedConfig.preview_url,
                },
            });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();
            const { rerender } = render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i);
            await user.clear(ftpInput);
            await user.type(ftpInput, 'https://downloads.example.org/unsaved-file.zip');

            await user.click(screen.getByRole('button', { name: /add link/i }));
            await user.type(screen.getByPlaceholderText(/display text/i), 'Temporary Link');
            await user.type(screen.getByPlaceholderText('https://...'), 'https://example.org/temp');

            await user.click(screen.getByRole('button', { name: /create preview/i }));

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalled();
            });

            mockModalGetRequests({ landingPage: savedConfig });

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={false}
                    onClose={mockOnClose}
                />,
            );

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const reopenedFtpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(reopenedFtpInput.value).toBe('https://saved.example.org/final-file.zip');
            expect(screen.queryByDisplayValue('Temporary Link')).not.toBeInTheDocument();
            expect(screen.queryByDisplayValue('https://example.org/temp')).not.toBeInTheDocument();
        });

        it('ignores sessionStorage read errors and falls back to the server state', async () => {
            const getItemSpy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new DOMException('sessionStorage is unavailable', 'QuotaExceededError');
            });

            try {
                mockModalGetRequests({ landingPage: mockExistingConfig });

                render(
                    <SetupLandingPageModal
                        resource={mockResource}
                        isOpen={true}
                        onClose={mockOnClose}
                    />,
                );

                const ftpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

                expect(ftpInput.value).toBe(mockExistingConfig.ftp_url);
            } finally {
                getItemSpy.mockRestore();
            }
        });

        it('ignores sessionStorage write errors while the user edits the modal', async () => {
            const setItemSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new DOMException('sessionStorage is unavailable', 'QuotaExceededError');
            });

            try {
                mockModalGetRequests({ landingPage: null });

                const user = userEvent.setup();
                render(
                    <SetupLandingPageModal
                        resource={mockResource}
                        isOpen={true}
                        onClose={mockOnClose}
                    />,
                );

                const ftpInput = await screen.findByLabelText(/^Download URL$/i);
                await user.clear(ftpInput);
                await user.type(ftpInput, 'https://downloads.example.org/storage-safe.zip');

                expect((ftpInput as HTMLInputElement).value).toBe('https://downloads.example.org/storage-safe.zip');
            } finally {
                setItemSpy.mockRestore();
            }
        });

        it('ignores sessionStorage removal errors after a successful save', async () => {
            const removeItemSpy = vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
                throw new DOMException('sessionStorage is unavailable', 'QuotaExceededError');
            });

            try {
                mockModalGetRequests({ landingPage: null });

                const savedConfig: LandingPageConfig = {
                    ...mockExistingConfig,
                    status: 'draft',
                    ftp_url: 'https://saved.example.org/final-file.zip',
                    links: [],
                };

                mockedAxiosPost.mockResolvedValue({
                    data: {
                        message: 'Landing page saved',
                        landing_page: savedConfig,
                        preview_url: savedConfig.preview_url,
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

                const ftpInput = await screen.findByLabelText(/^Download URL$/i);
                await user.clear(ftpInput);
                await user.type(ftpInput, savedConfig.ftp_url ?? '');
                await user.click(screen.getByRole('button', { name: /create preview/i }));

                await waitFor(() => {
                    expect(mockedAxiosPost).toHaveBeenCalled();
                });

                expect(screen.getByRole('dialog')).toBeInTheDocument();
            } finally {
                removeItemSpy.mockRestore();
            }
        });

        it('ignores malformed persisted draft data and falls back to the server state', async () => {
            window.sessionStorage.setItem('setup-landing-page-modal:draft:123', '{not-valid-json');
            mockModalGetRequests({ landingPage: mockExistingConfig });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(ftpInput.value).toBe(mockExistingConfig.ftp_url);
        });

        it('ignores persisted draft entries without a template and falls back to the server state', async () => {
            window.sessionStorage.setItem(
                'setup-landing-page-modal:draft:123',
                JSON.stringify({
                    ftpUrl: 'https://downloads.example.org/invalid.zip',
                    links: [{ label: 'Invalid persisted link', url: 'https://example.org/invalid', position: 0 }],
                }),
            );
            mockModalGetRequests({ landingPage: mockExistingConfig });

            render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const ftpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(ftpInput.value).toBe(mockExistingConfig.ftp_url);
            expect(screen.queryByDisplayValue('Invalid persisted link')).not.toBeInTheDocument();
        });

        it('clears a persisted draft after removing a preview and reopens with empty defaults', async () => {
            const draftConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft',
                ftp_url: 'https://saved.example.org/current.zip',
            };

            window.sessionStorage.setItem(
                'setup-landing-page-modal:draft:123',
                JSON.stringify({
                    template: 'default_gfz',
                    ftpUrl: 'https://downloads.example.org/stale.zip',
                    isPublished: false,
                    externalDomainId: '',
                    externalPath: '',
                    landingPageTemplateId: null,
                    links: [
                        {
                            label: 'Stale link',
                            url: 'https://example.org/stale',
                            position: 0,
                        },
                    ],
                }),
            );

            mockModalGetRequests({ landingPage: draftConfig });
            mockedAxiosDelete.mockResolvedValue({ data: { message: 'Landing page deleted successfully' } });
            vi.stubGlobal('confirm', vi.fn(() => true));

            const user = userEvent.setup();
            const { rerender } = render(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await screen.findByRole('dialog');
            await user.click(screen.getByRole('button', { name: /remove preview/i }));

            await waitFor(() => {
                expect(mockedAxiosDelete).toHaveBeenCalledWith(`/resources/${mockResource.id}/landing-page`);
            });

            mockModalGetRequests({ landingPage: null });

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={false}
                    onClose={mockOnClose}
                />,
            );

            rerender(
                <SetupLandingPageModal
                    resource={mockResource}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            const reopenedFtpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(reopenedFtpInput.value).toBe('');
            expect(screen.queryByDisplayValue('Stale link')).not.toBeInTheDocument();
            expect(screen.queryByDisplayValue('https://example.org/stale')).not.toBeInTheDocument();

            vi.unstubAllGlobals();
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

        it('keeps an igsn custom template id in the save payload for Physical Object resources', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 7,
                                    name: 'Custom Sample Layout',
                                    slug: 'custom-sample-layout',
                                    is_default: false,
                                    template_type: 'igsn',
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
                    landing_page: {
                        ...mockExistingConfig,
                        template: 'default_gfz_igsn',
                        landing_page_template_id: 7,
                        landing_page_template: {
                            id: 7,
                            name: 'Custom Sample Layout',
                            slug: 'custom-sample-layout',
                            is_default: false,
                            template_type: 'igsn',
                            logo_path: null,
                            logo_url: null,
                            right_column_order: [],
                            left_column_order: [],
                        },
                        ftp_url: null,
                        status: 'draft',
                    },
                    preview_url: '/preview',
                },
            });
            mockedAxiosDelete.mockResolvedValue({});

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            await user.click(screen.getByLabelText(/Landing Page Template/i));

            await waitFor(() => {
                expect(screen.getByText('Custom Sample Layout')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Custom Sample Layout'));
            await user.click(screen.getByRole('button', { name: /Create Preview/i }));

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        landing_page_template_id: 7,
                    }),
                );
            });

            expect(mockedAxiosPost.mock.calls.at(-1)?.[1]).not.toHaveProperty('ftp_url');
        });

        it('keeps an igsn custom template id in the preview payload for Physical Object resources', async () => {
            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({
                        data: {
                            templates: [
                                {
                                    id: 8,
                                    name: 'Preview Sample Layout',
                                    slug: 'preview-sample-layout',
                                    is_default: false,
                                    template_type: 'igsn',
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
            mockedAxiosPost.mockImplementation((url: string) => {
                if (url.includes('/landing-page/preview')) {
                    return Promise.resolve({ data: { preview_url: '/resources/123/landing-page/preview' } });
                }

                return Promise.resolve({
                    data: {
                        message: 'Landing page created',
                        landing_page: { ...mockExistingConfig, status: 'draft' },
                        preview_url: '/preview',
                    },
                });
            });

            const mockOpen = vi.fn();
            vi.stubGlobal('open', mockOpen);

            const user = userEvent.setup();

            render(
                <SetupLandingPageModal
                    resource={{ ...mockResource, resourcetypegeneral: 'Physical Object' }}
                    isOpen={true}
                    onClose={mockOnClose}
                />,
            );

            await waitFor(() => {
                expect(screen.getByRole('dialog')).toBeInTheDocument();
            });

            await user.click(screen.getByLabelText(/Landing Page Template/i));

            await waitFor(() => {
                expect(screen.getByText('Preview Sample Layout')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Preview Sample Layout'));

            const previewButtons = screen.getAllByRole('button', { name: /Preview/i });
            const previewButton = previewButtons.find(
                (btn) => btn.className.includes('outline') || btn.textContent?.trim() === 'Preview'
            );

            expect(previewButton).toBeDefined();
            await user.click(previewButton!);

            await waitFor(() => {
                expect(mockedAxiosPost).toHaveBeenCalledWith(
                    expect.stringContaining(`/resources/${mockResource.id}/landing-page/preview`),
                    expect.objectContaining({
                        template: 'default_gfz_igsn',
                        landing_page_template_id: 8,
                    }),
                );
            });

            vi.unstubAllGlobals();
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

        it('restores a persisted draft when loading the server config fails with a non-404 error', async () => {
            const { toast } = await import('sonner');

            window.sessionStorage.setItem(
                'setup-landing-page-modal:draft:123',
                JSON.stringify({
                    template: 'default_gfz',
                    ftpUrl: 'https://downloads.example.org/persisted.zip',
                    isPublished: false,
                    externalDomainId: '',
                    externalPath: '',
                    landingPageTemplateId: null,
                    links: [
                        {
                            label: 'Persisted link',
                            url: 'https://example.org/persisted',
                            position: 0,
                        },
                    ],
                }),
            );

            mockedAxiosGet.mockImplementation((url: string) => {
                if (url.includes('/api/landing-page-templates')) {
                    return Promise.resolve({ data: { templates: [] } });
                }
                if (url.includes('/api/landing-page-domains')) {
                    return Promise.resolve({ data: { domains: [] } });
                }

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

            const ftpInput = await screen.findByLabelText(/^Download URL$/i) as HTMLInputElement;

            expect(ftpInput.value).toBe('https://downloads.example.org/persisted.zip');
            expect(screen.getByDisplayValue('Persisted link')).toBeInTheDocument();
            expect(screen.getByDisplayValue('https://example.org/persisted')).toBeInTheDocument();
            expect(toast.error).toHaveBeenCalledWith('Failed to load landing page configuration');
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

        it('drops a persisted built-in default template id when the loaded relation is the default template', async () => {
            const serverConfig: LandingPageConfig = {
                ...mockExistingConfig,
                status: 'draft' as const,
                template: 'default_gfz',
                landing_page_template_id: 1,
                landing_page_template: {
                    id: 1,
                    name: 'Default GFZ Data Services',
                    slug: 'default_gfz',
                    is_default: true,
                    template_type: 'resource',
                    logo_path: null,
                    logo_url: null,
                    right_column_order: [],
                    left_column_order: [],
                },
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
            mockedAxiosPut.mockResolvedValue({
                data: {
                    landing_page: {
                        ...serverConfig,
                        landing_page_template_id: null,
                    },
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
                expect(screen.getByLabelText(/Landing Page Template/i)).toHaveTextContent('Default GFZ Data Services');
            });

            await user.click(screen.getByRole('button', { name: /Update/i }));

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
