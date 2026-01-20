import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { OrcidSearchDialog } from '@/components/curation/fields/orcid-search-dialog';
import type { OrcidSearchResult } from '@/services/orcid';
import { OrcidService } from '@/services/orcid';

// Type for OrcidService.searchOrcid response
type OrcidServiceResponse = {
    success: boolean;
    data?: {
        results: OrcidSearchResult[];
        total: number;
    };
    error?: string;
};

// Mock OrcidService
vi.mock('@/services/orcid', () => ({
    OrcidService: {
        searchOrcid: vi.fn(),
    },
}));

describe('OrcidSearchDialog', () => {
    const mockOnSelect = vi.fn();

    const mockSearchResults = [
        {
            orcid: '0000-0001-2345-6789',
            firstName: 'John',
            lastName: 'Smith',
            creditName: null,
            institutions: ['GFZ German Research Centre for Geosciences', 'University of Berlin'],
        },
        {
            orcid: '0000-0002-3456-7890',
            firstName: 'Jane',
            lastName: 'Doe',
            creditName: null,
            institutions: ['Max Planck Institute'],
        },
        {
            orcid: '0000-0003-4567-8901',
            firstName: 'Test',
            lastName: 'User',
            creditName: null,
            institutions: [],
        },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('trigger button', () => {
        it('renders search button with accessible label', () => {
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            expect(screen.getByRole('button', { name: /search for orcid/i })).toBeInTheDocument();
        });

        it('applies custom trigger class name', () => {
            render(<OrcidSearchDialog onSelect={mockOnSelect} triggerClassName="custom-class" />);

            expect(screen.getByRole('button', { name: /search for orcid/i })).toHaveClass('custom-class');
        });
    });

    describe('dialog opening', () => {
        it('opens dialog when trigger is clicked', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        it('displays dialog title', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText('Search for ORCID')).toBeInTheDocument();
        });

        it('displays dialog description', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText(/search for orcid records by name/i)).toBeInTheDocument();
        });

        it('shows initial empty state before search', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText(/start typing to search orcid records/i)).toBeInTheDocument();
        });
    });

    describe('search input', () => {
        it('renders search input field with placeholder', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByRole('combobox')).toBeInTheDocument();
            expect(screen.getByPlaceholderText(/search by name, institution/i)).toBeInTheDocument();
        });

        it('allows typing in search input', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'John Smith');

            expect(input).toHaveValue('John Smith');
        });
    });

    describe('search functionality', () => {
        it('calls OrcidService.searchOrcid after debounce when typing', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], total: 0 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'John');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            expect(OrcidService.searchOrcid).toHaveBeenCalledWith('John', 20);
        });

        it('does not search for queries shorter than 2 characters', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], total: 0 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'J');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            expect(OrcidService.searchOrcid).not.toHaveBeenCalled();
        });

        it('shows loading state while searching', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            let resolveSearch!: (value: OrcidServiceResponse) => void;
            vi.mocked(OrcidService.searchOrcid).mockImplementation(
                () =>
                    new Promise<OrcidServiceResponse>((resolve) => {
                        resolveSearch = resolve;
                    }),
            );

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            // Wait for loading state to appear
            await waitFor(() => {
                expect(screen.getByText(/searching orcid database/i)).toBeInTheDocument();
            });

            // Resolve the search
            resolveSearch!({ success: true, data: { results: [], total: 0 } });
        });

        it('shows no results message when search returns empty', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], total: 0 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'nonexistent12345');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/no orcid records found/i)).toBeInTheDocument();
            });
        });
    });

    describe('search results', () => {
        it('displays search results', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/Smith, John/i)).toBeInTheDocument();
                expect(screen.getByText('0000-0001-2345-6789')).toBeInTheDocument();
            });
        });

        it('displays results count in group heading', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/3 results found/i)).toBeInTheDocument();
            });
        });

        it('displays institutions for each result', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/GFZ German Research Centre/i)).toBeInTheDocument();
                expect(screen.getByText(/Max Planck Institute/i)).toBeInTheDocument();
            });
        });
    });

    describe('result selection', () => {
        it('calls onSelect with result when item is selected', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/Smith, John/i)).toBeInTheDocument();
            });

            // Click on the result item
            await user.click(screen.getByText(/Smith, John/i));

            expect(mockOnSelect).toHaveBeenCalledWith(mockSearchResults[0]);
        });

        it('closes dialog after selection', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/Smith, John/i)).toBeInTheDocument();
            });

            await user.click(screen.getByText(/Smith, John/i));

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });
        });

        it('resets dialog state after selection', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, total: 3 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            // First search
            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/Smith, John/i)).toBeInTheDocument();
            });

            await user.click(screen.getByText(/Smith, John/i));

            // Reopen dialog
            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByRole('combobox')).toHaveValue('');
            expect(screen.getByText(/start typing to search orcid records/i)).toBeInTheDocument();
        });
    });

    describe('error handling', () => {
        it('shows empty results on API error', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockRejectedValue(new Error('Network error'));

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/no orcid records found/i)).toBeInTheDocument();
            });
        });

        it('handles unsuccessful response', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: false,
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                expect(screen.getByText(/no orcid records found/i)).toBeInTheDocument();
            });
        });
    });

    describe('external links', () => {
        it('renders link to ORCID.org for each result', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [mockSearchResults[0]], total: 1 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            const input = screen.getByRole('combobox');
            await user.type(input, 'test');

            // Advance past debounce timer
            await vi.advanceTimersByTimeAsync(350);

            await waitFor(() => {
                const link = screen.getByRole('link', { name: /view on orcid\.org/i });
                expect(link).toHaveAttribute('href', 'https://orcid.org/0000-0001-2345-6789');
                expect(link).toHaveAttribute('target', '_blank');
                expect(link).toHaveAttribute('rel', 'noopener noreferrer');
            });
        });
    });
});
