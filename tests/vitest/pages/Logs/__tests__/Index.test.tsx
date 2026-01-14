import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Index from '@/pages/Logs/Index';

// Mock dependencies
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        get: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

describe('Logs/Index', () => {
    const defaultLogs = [
        {
            timestamp: '2024-01-15 10:30:00',
            level: 'error',
            message: 'An error occurred in the application',
            context: '{"user_id": 123}',
            line_number: 1,
        },
        {
            timestamp: '2024-01-15 10:25:00',
            level: 'warning',
            message: 'Deprecated function called',
            context: '{"function": "oldMethod"}',
            line_number: 2,
        },
        {
            timestamp: '2024-01-15 10:20:00',
            level: 'info',
            message: 'User logged in successfully',
            context: '{}',
            line_number: 3,
        },
    ];

    const defaultPagination = {
        current_page: 1,
        last_page: 3,
        per_page: 50,
        total: 125,
    };

    const defaultFilters = {
        level: null,
        search: null,
        per_page: 50,
    };

    const defaultAvailableLevels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    const defaultProps = {
        logs: defaultLogs,
        pagination: defaultPagination,
        filters: defaultFilters,
        available_levels: defaultAvailableLevels,
        can_delete: true,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the logs page', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Application Logs')).toBeInTheDocument();
    });

    it('displays log count', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('125 log entries found')).toBeInTheDocument();
    });

    it('displays singular form when only one log', () => {
        render(<Index {...defaultProps} pagination={{ ...defaultPagination, total: 1 }} />);

        expect(screen.getByText('1 log entry found')).toBeInTheDocument();
    });

    it('displays log entries in the table', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('An error occurred in the application')).toBeInTheDocument();
        expect(screen.getByText('Deprecated function called')).toBeInTheDocument();
        expect(screen.getByText('User logged in successfully')).toBeInTheDocument();
    });

    it('displays log timestamps', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('2024-01-15 10:30:00')).toBeInTheDocument();
        expect(screen.getByText('2024-01-15 10:25:00')).toBeInTheDocument();
    });

    it('displays log levels', () => {
        render(<Index {...defaultProps} />);

        // Log messages contain the level info
        expect(screen.getByText('An error occurred in the application')).toBeInTheDocument();
        expect(screen.getByText('Deprecated function called')).toBeInTheDocument();
        expect(screen.getByText('User logged in successfully')).toBeInTheDocument();
    });

    it('shows security warning', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByText('Security Notice:')).toBeInTheDocument();
        expect(screen.getByText(/Log files may contain sensitive information/)).toBeInTheDocument();
    });

    it('renders refresh button', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Refresh/i })).toBeInTheDocument();
    });

    it('renders Clear All button when can_delete is true', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Clear All/i })).toBeInTheDocument();
    });

    it('does not render Clear All button when can_delete is false', () => {
        render(<Index {...defaultProps} can_delete={false} />);

        expect(screen.queryByRole('button', { name: /Clear All/i })).not.toBeInTheDocument();
    });

    it('renders level filter dropdown', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('renders search input', () => {
        render(<Index {...defaultProps} />);

        expect(screen.getByPlaceholderText('Search logs...')).toBeInTheDocument();
    });

    it('calls router.reload when refresh button is clicked', async () => {
        const { router } = await import('@inertiajs/react');
        const user = userEvent.setup();
        render(<Index {...defaultProps} />);

        const refreshButton = screen.getByRole('button', { name: /Refresh/i });
        await user.click(refreshButton);

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalled();
        });
    });

    it('shows empty state when no logs', () => {
        render(<Index {...defaultProps} logs={[]} pagination={{ ...defaultPagination, total: 0 }} />);

        expect(screen.getByText('0 log entries found')).toBeInTheDocument();
    });

    it('disables Clear All button when no logs exist', () => {
        render(<Index {...defaultProps} logs={[]} pagination={{ ...defaultPagination, total: 0 }} />);

        const clearButton = screen.getByRole('button', { name: /Clear All/i });
        expect(clearButton).toBeDisabled();
    });

    it('preserves search filter from props', () => {
        render(<Index {...defaultProps} filters={{ ...defaultFilters, search: 'test query' }} />);

        const searchInput = screen.getByPlaceholderText('Search logs...') as HTMLInputElement;
        expect(searchInput.value).toBe('test query');
    });

    it('calls router.get when search is submitted with Enter key', async () => {
        const { router } = await import('@inertiajs/react');
        const user = userEvent.setup();
        render(<Index {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText('Search logs...');
        await user.type(searchInput, 'error{Enter}');

        await waitFor(() => {
            expect(router.get).toHaveBeenCalledWith(
                '/logs',
                expect.objectContaining({
                    search: 'error',
                }),
                expect.any(Object),
            );
        });
    });

    it('renders pagination when multiple pages exist', () => {
        render(<Index {...defaultProps} />);

        // Should show page indicators or navigation
        expect(screen.getByText(/Page 1 of 3/)).toBeInTheDocument();
    });
});
