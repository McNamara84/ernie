import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RegisterDoiModal from '@/components/resources/modals/RegisterDoiModal';

// Mock axios
const mockGet = vi.fn();
const mockPost = vi.fn();
vi.mock('axios', () => ({
    default: {
        get: (...args: unknown[]) => mockGet(...args),
        post: (...args: unknown[]) => mockPost(...args),
    },
    isAxiosError: (error: unknown) => !!(error && typeof error === 'object' && 'isAxiosError' in error),
}));

// Mock toast
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

// Mock Inertia usePage
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        usePage: () => ({
            props: {
                auth: {
                    user: {
                        id: 1,
                        name: 'Test User',
                        email: 'test@example.com',
                        role: 'admin',
                        can_manage_users: true,
                        can_register_production_doi: true,
                    },
                },
            },
        }),
    };
});

describe('RegisterDoiModal', () => {
    const mockResource = {
        id: 1,
        title: 'Test Resource',
        doi: null,
        landingPage: {
            id: 1,
            status: 'draft',
            public_url: 'https://example.com/preview/123',
        },
    };

    const mockResourceWithDoi = {
        ...mockResource,
        doi: '10.83279/existing-doi',
    };

    const mockPrefixConfig = {
        test: ['10.83279', '10.83186', '10.83114'],
        production: ['10.5880', '10.26026', '10.14470'],
        test_mode: true,
    };

    const defaultProps = {
        resource: mockResource,
        isOpen: true,
        onClose: vi.fn(),
        onSuccess: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();

        // Default mock for prefix config
        mockGet.mockResolvedValue({
            data: mockPrefixConfig,
        });
    });

    it('renders modal with correct title for new DOI registration', async () => {
        render(<RegisterDoiModal {...defaultProps} />);

        expect(screen.getByText('Register DOI with DataCite')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.getByText(/register a new doi/i)).toBeInTheDocument();
        });
    });

    it('renders modal with correct title for DOI update', async () => {
        render(<RegisterDoiModal {...defaultProps} resource={mockResourceWithDoi} />);

        expect(screen.getByText('Update DOI Metadata')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.getByText(/update metadata for existing doi/i)).toBeInTheDocument();
        });
    });

    it('displays landing page requirement warning when no landing page exists', async () => {
        const resourceWithoutLandingPage = {
            ...mockResource,
            landingPage: null,
        };

        render(<RegisterDoiModal {...defaultProps} resource={resourceWithoutLandingPage} />);

        await waitFor(() => {
            expect(screen.getByText('Landing Page Required')).toBeInTheDocument();
            expect(
                screen.getByText(/a landing page must be created before you can register a doi/i)
            ).toBeInTheDocument();
        });
    });

    it('displays test mode warning in test mode', async () => {
        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(screen.getByText('Test Mode Active')).toBeInTheDocument();
            expect(
                screen.getByText(/you are using the datacite test environment/i)
            ).toBeInTheDocument();
        });
    });

    it('loads and displays available prefixes from backend', async () => {
        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(mockGet).toHaveBeenCalledWith('/api/datacite/prefixes');
        });

        // Click on select to open dropdown
        const selectTrigger = screen.getByRole('combobox');
        await userEvent.click(selectTrigger);

        // Check if prefixes are displayed
        await waitFor(() => {
            expect(screen.getAllByText('10.83279').length).toBeGreaterThan(0);
            expect(screen.getAllByText('10.83186').length).toBeGreaterThan(0);
            expect(screen.getAllByText('10.83114').length).toBeGreaterThan(0);
        });
    });

    it('selects first prefix by default for new DOI', async () => {
        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            const selectTrigger = screen.getByRole('combobox');
            expect(selectTrigger).toHaveTextContent('10.83279');
        });
    });

    it('displays existing DOI info when updating', async () => {
        render(<RegisterDoiModal {...defaultProps} resource={mockResourceWithDoi} />);

        await waitFor(() => {
            expect(screen.getByText('Existing DOI')).toBeInTheDocument();
            expect(screen.getByText('10.83279/existing-doi')).toBeInTheDocument();
            expect(
                screen.getByText(/submitting will update the metadata at datacite/i)
            ).toBeInTheDocument();
        });
    });

    it('disables submit button when no landing page exists', async () => {
        const resourceWithoutLandingPage = {
            ...mockResource,
            landingPage: null,
        };

        render(<RegisterDoiModal {...defaultProps} resource={resourceWithoutLandingPage} />);

        await waitFor(() => {
            const submitButton = screen.getByRole('button', { name: /register doi/i });
            expect(submitButton).toBeDisabled();
        });
    });

    it('submits DOI registration with correct data', async () => {
        const user = userEvent.setup();

        mockPost.mockResolvedValue({
            data: {
                success: true,
                message: 'DOI registered successfully',
                doi: '10.83279/new-doi-123',
                mode: 'test',
                updated: false,
            },
        });

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
        });

        const submitButton = screen.getByRole('button', { name: /register doi/i });
        await user.click(submitButton);

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/resources/1/register-doi', {
                prefix: '10.83279',
                force: false,
            });
        });
    });

    it('calls onSuccess callback after successful registration', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();

        mockPost.mockResolvedValue({
            data: {
                success: true,
                message: 'DOI registered successfully',
                doi: '10.83279/new-doi-123',
                mode: 'test',
                updated: false,
            },
        });

        render(<RegisterDoiModal {...defaultProps} onSuccess={onSuccess} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
        });

        const submitButton = screen.getByRole('button', { name: /register doi/i });
        await user.click(submitButton);

        await waitFor(() => {
            expect(onSuccess).toHaveBeenCalledWith('10.83279/new-doi-123');
        });
    });

    it('calls onClose after successful registration', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();

        mockPost.mockResolvedValue({
            data: {
                success: true,
                message: 'DOI registered successfully',
                doi: '10.83279/new-doi-123',
                mode: 'test',
                updated: false,
            },
        });

        render(<RegisterDoiModal {...defaultProps} onClose={onClose} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
        });

        const submitButton = screen.getByRole('button', { name: /register doi/i });
        await user.click(submitButton);

        await waitFor(() => {
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('displays error message on registration failure', async () => {
        const user = userEvent.setup();

        mockPost.mockRejectedValue({
            response: {
                data: {
                    success: false,
                    message: 'Invalid metadata',
                },
            },
            isAxiosError: true,
        });

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
        });

        const submitButton = screen.getByRole('button', { name: /register doi/i });
        await user.click(submitButton);

        await waitFor(() => {
            expect(screen.getByText('Error')).toBeInTheDocument();
            expect(screen.getByText('Invalid metadata')).toBeInTheDocument();
        });
    });

    it('changes button text to "Update Metadata" for existing DOI', async () => {
        render(<RegisterDoiModal {...defaultProps} resource={mockResourceWithDoi} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /update metadata/i })).toBeInTheDocument();
        });
    });

    it('allows prefix selection', async () => {
        const user = userEvent.setup();

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });

        const selectTrigger = screen.getByRole('combobox');
        await user.click(selectTrigger);

        const option = screen.getByText('10.83186');
        await user.click(option);

        await waitFor(() => {
            expect(selectTrigger).toHaveTextContent('10.83186');
        });
    });

    it('displays error when API call fails and shows no prefixes', async () => {
        mockGet.mockRejectedValue(new Error('Network error'));

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            // Should show error message
            expect(screen.getByText('Error')).toBeInTheDocument();
            expect(screen.getByText(/failed to load doi prefix configuration/i)).toBeInTheDocument();
        });

        // Select should show "Select a prefix" (no default prefix)
        const selectTrigger = screen.getByRole('combobox');
        expect(selectTrigger).toHaveTextContent('Select a prefix');

        // Submit button should be disabled when no prefix is available
        const submitButton = screen.getByRole('button', { name: /register doi/i });
        expect(submitButton).toBeDisabled();
    });

    it('resets state when modal closes', async () => {
        const { rerender } = render(<RegisterDoiModal {...defaultProps} />);

        // Wait for initial load
        await waitFor(() => {
            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });

        // Close modal
        rerender(<RegisterDoiModal {...defaultProps} isOpen={false} />);

        // Reopen modal
        rerender(<RegisterDoiModal {...defaultProps} isOpen={true} />);

        // Should reload config
        await waitFor(() => {
            expect(mockGet).toHaveBeenCalledTimes(2);
        });
    });

    it('disables buttons while submitting', async () => {
        const user = userEvent.setup();

        mockPost.mockImplementation(
            () => new Promise((resolve) => setTimeout(resolve, 1000))
        );

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
        });

        const submitButton = screen.getByRole('button', { name: /register doi/i });
        await user.click(submitButton);

        // Buttons should be disabled while submitting. With <LoadingButton> the
        // accessible name stays "Register DOI" – only the spinner + aria-busy
        // indicate progress. The Cancel button is gated by the same isSubmitting flag.
        const loadingButton = screen.getByRole('button', { name: /register doi/i });
        expect(loadingButton).toBeDisabled();
        expect(loadingButton).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByRole('button', { name: /cancel/i })).toBeDisabled();
    });

    // --- Issue #610: ORCID preflight ---
    describe('ORCID preflight', () => {
        const makeAxiosError = (status: number, data: unknown) => ({
            isAxiosError: true,
            response: { status, data },
        });

        it('renders blocking alert and disables submit when backend returns 422', async () => {
            const user = userEvent.setup();

            mockPost.mockRejectedValueOnce(
                makeAxiosError(422, {
                    error: 'orcid_validation_failed',
                    message: 'ORCID validation failed',
                    invalid: [
                        {
                            severity: 'blocking',
                            reason: 'not_found',
                            role: 'creator',
                            position: 0,
                            orcid: '0000-0001-2345-6789',
                            displayName: 'Jane Doe',
                        },
                    ],
                    warnings: [],
                }),
            );

            render(<RegisterDoiModal {...defaultProps} />);

            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const alert = await screen.findByTestId('orcid-preflight-blockers');
            expect(alert).toBeInTheDocument();
            expect(alert).toHaveTextContent('Jane Doe');
            expect(alert).toHaveTextContent('0000-0001-2345-6789');
            expect(alert).toHaveTextContent(/not found in orcid registry/i);

            // No warnings alert rendered alongside blockers.
            expect(screen.queryByTestId('orcid-preflight-warnings')).not.toBeInTheDocument();

            // Primary submit stays disabled due to blockers.
            expect(screen.getByRole('button', { name: /register doi/i })).toBeDisabled();

            // No override button is offered when hard blockers exist.
            expect(screen.queryByTestId('orcid-preflight-override')).not.toBeInTheDocument();
        });

        it('renders warning alert and offers "Register anyway" override on 409', async () => {
            const user = userEvent.setup();

            // First call: 409 warning, second call (forced): success.
            mockPost
                .mockRejectedValueOnce(
                    makeAxiosError(409, {
                        error: 'orcid_validation_warning',
                        message: 'ORCID service unavailable',
                        invalid: [],
                        warnings: [
                            {
                                severity: 'warning',
                                reason: 'timeout',
                                role: 'contributor',
                                position: 2,
                                orcid: '0000-0002-3333-4444',
                                displayName: 'Alex Contributor',
                            },
                        ],
                    }),
                )
                .mockResolvedValueOnce({
                    data: {
                        success: true,
                        message: 'DOI registered successfully',
                        doi: '10.83279/forced-doi',
                        mode: 'test',
                        updated: false,
                    },
                });

            const onSuccess = vi.fn();

            render(<RegisterDoiModal {...defaultProps} onSuccess={onSuccess} />);

            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const warningAlert = await screen.findByTestId('orcid-preflight-warnings');
            expect(warningAlert).toHaveTextContent('Alex Contributor');
            expect(warningAlert).toHaveTextContent(/orcid service timed out/i);

            // Blockers alert not rendered alongside warnings.
            expect(screen.queryByTestId('orcid-preflight-blockers')).not.toBeInTheDocument();

            // Override button replaces the regular submit button.
            const overrideButton = await screen.findByTestId('orcid-preflight-override');
            expect(overrideButton).toHaveTextContent(/register anyway/i);

            await user.click(overrideButton);

            await waitFor(() => {
                expect(mockPost).toHaveBeenLastCalledWith('/resources/1/register-doi', {
                    prefix: '10.83279',
                    force: true,
                });
            });

            await waitFor(() => {
                expect(onSuccess).toHaveBeenCalledWith('10.83279/forced-doi');
            });
        });

        it('renders plural identifier count when multiple ORCIDs are invalid', async () => {
            const user = userEvent.setup();

            mockPost.mockRejectedValueOnce(
                makeAxiosError(422, {
                    error: 'orcid_validation_failed',
                    message: 'ORCID validation failed',
                    invalid: [
                        {
                            severity: 'blocking',
                            reason: 'not_found',
                            role: 'creator',
                            position: 0,
                            orcid: '0000-0001-2345-6789',
                            displayName: 'Jane Doe',
                        },
                        {
                            severity: 'blocking',
                            reason: 'checksum',
                            role: 'creator',
                            position: 1,
                            orcid: '0000-0002-1111-1111',
                            displayName: 'Bob Smith',
                        },
                    ],
                    warnings: [],
                }),
            );

            render(<RegisterDoiModal {...defaultProps} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });
            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const alert = await screen.findByTestId('orcid-preflight-blockers');
            expect(alert).toHaveTextContent(/2 identifiers/i);
            expect(alert).toHaveTextContent('Jane Doe');
            expect(alert).toHaveTextContent('Bob Smith');
        });

        it('swallows errors thrown by onSuccess callback without breaking the modal', async () => {
            const user = userEvent.setup();

            mockPost.mockResolvedValueOnce({
                data: {
                    success: true,
                    message: 'DOI registered successfully',
                    doi: '10.83279/new-doi',
                    mode: 'test',
                    updated: false,
                },
            });

            // onSuccess throws synchronously – modal must NOT re-throw.
            const onSuccess = vi.fn(() => {
                throw new Error('callback boom');
            });
            const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

            render(<RegisterDoiModal {...defaultProps} onSuccess={onSuccess} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            await waitFor(() => {
                expect(onSuccess).toHaveBeenCalledWith('10.83279/new-doi');
            });
            expect(consoleSpy).toHaveBeenCalled();

            consoleSpy.mockRestore();
        });

        it('re-displays generic error message when 500 response has no ORCID payload', async () => {
            const user = userEvent.setup();

            mockPost.mockRejectedValueOnce({
                isAxiosError: true,
                response: {
                    status: 500,
                    data: { success: false, message: 'Internal server error', doi: '', mode: 'test', updated: false },
                },
            });

            render(<RegisterDoiModal {...defaultProps} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            await waitFor(() => {
                expect(screen.getByText(/internal server error/i)).toBeInTheDocument();
            });
            // No preflight alerts.
            expect(screen.queryByTestId('orcid-preflight-blockers')).not.toBeInTheDocument();
            expect(screen.queryByTestId('orcid-preflight-warnings')).not.toBeInTheDocument();
        });

        it('offers a "Retry verification" button that re-runs preflight with force=false', async () => {
            const user = userEvent.setup();

            const warningResponse = makeAxiosError(409, {
                error: 'orcid_validation_warning',
                message: 'ORCID service unavailable',
                invalid: [],
                warnings: [
                    {
                        severity: 'warning',
                        reason: 'timeout',
                        role: 'creator',
                        position: 0,
                        orcid: '0000-0002-1825-0097',
                        displayName: 'Jane Doe',
                    },
                ],
            });

            // First: warning. Second (retry): success.
            mockPost
                .mockRejectedValueOnce(warningResponse)
                .mockResolvedValueOnce({
                    data: {
                        success: true,
                        message: 'DOI registered successfully',
                        doi: '10.83279/retried-doi',
                        mode: 'test',
                        updated: false,
                    },
                });

            const onSuccess = vi.fn();
            render(<RegisterDoiModal {...defaultProps} onSuccess={onSuccess} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const retryButton = await screen.findByTestId('orcid-preflight-retry');
            expect(retryButton).toHaveTextContent(/retry verification/i);

            await user.click(retryButton);

            // Second call must use force=false (not an override).
            await waitFor(() => {
                expect(mockPost).toHaveBeenLastCalledWith('/resources/1/register-doi', {
                    prefix: '10.83279',
                    force: false,
                });
            });

            await waitFor(() => {
                expect(onSuccess).toHaveBeenCalledWith('10.83279/retried-doi');
            });

            // Warning state cleared after successful retry.
            expect(screen.queryByTestId('orcid-preflight-warnings')).not.toBeInTheDocument();
        });

        it('only shows the loading indicator on the clicked preflight action button', async () => {
            const user = userEvent.setup();

            const warningResponse = makeAxiosError(409, {
                error: 'orcid_validation_warning',
                message: 'ORCID service unavailable',
                invalid: [],
                warnings: [
                    {
                        severity: 'warning',
                        reason: 'timeout',
                        role: 'creator',
                        position: 0,
                        orcid: '0000-0002-1825-0097',
                        displayName: 'Jane Doe',
                    },
                ],
            });

            // First call: warning. Second (override): pending forever so we can
            // observe the loading state while the request is in flight.
            mockPost
                .mockRejectedValueOnce(warningResponse)
                .mockImplementationOnce(() => new Promise(() => {}));

            render(<RegisterDoiModal {...defaultProps} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const overrideButton = await screen.findByTestId('orcid-preflight-override');
            const retryButton = screen.getByTestId('orcid-preflight-retry');

            await user.click(overrideButton);

            // Only the override button is busy; retry is merely disabled.
            await waitFor(() => {
                expect(overrideButton).toHaveAttribute('aria-busy', 'true');
            });
            expect(retryButton).toBeDisabled();
            expect(retryButton).not.toHaveAttribute('aria-busy', 'true');
        });

        it('keeps the Retry button mounted with a loading indicator while the retry request is in flight', async () => {
            const user = userEvent.setup();

            const warningResponse = makeAxiosError(409, {
                error: 'orcid_validation_warning',
                message: 'ORCID service unavailable',
                invalid: [],
                warnings: [
                    {
                        severity: 'warning',
                        reason: 'timeout',
                        role: 'creator',
                        position: 0,
                        orcid: '0000-0002-1825-0097',
                        displayName: 'Jane Doe',
                    },
                ],
            });

            // First call: warning. Second (retry): pending forever so we can
            // observe the retry button's loading state while the request is in flight.
            mockPost
                .mockRejectedValueOnce(warningResponse)
                .mockImplementationOnce(() => new Promise(() => {}));

            render(<RegisterDoiModal {...defaultProps} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: /register doi/i })).not.toBeDisabled();
            });

            await user.click(screen.getByRole('button', { name: /register doi/i }));

            const retryButton = await screen.findByTestId('orcid-preflight-retry');
            await user.click(retryButton);

            // The retry button must remain mounted AND show its loading indicator
            // while the request is in flight. The warning alert stays visible so
            // the user has continuous feedback that their retry is being processed.
            await waitFor(() => {
                expect(screen.getByTestId('orcid-preflight-retry')).toHaveAttribute('aria-busy', 'true');
            });
            expect(screen.getByTestId('orcid-preflight-warnings')).toBeInTheDocument();
        });
    });
});
