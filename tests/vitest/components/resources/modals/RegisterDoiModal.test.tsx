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

// Mock withBasePath
vi.mock('@/lib/base-path', () => ({
    withBasePath: (path: string) => path,
}));

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

    it('falls back to default prefixes if API call fails', async () => {
        mockGet.mockRejectedValue(new Error('Network error'));

        render(<RegisterDoiModal {...defaultProps} />);

        await waitFor(() => {
            const selectTrigger = screen.getByRole('combobox');
            expect(selectTrigger).toHaveTextContent('10.83279');
        });
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

        // Buttons should be disabled while submitting
        expect(screen.getByRole('button', { name: /processing/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: /cancel/i })).toBeDisabled();
    });
});
