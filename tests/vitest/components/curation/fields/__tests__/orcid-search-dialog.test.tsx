import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OrcidSearchDialog } from '@/components/curation/fields/orcid-search-dialog';
import { OrcidService } from '@/services/orcid';

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
            institutions: ['GFZ German Research Centre for Geosciences', 'University of Berlin'],
        },
        {
            orcid: '0000-0002-3456-7890',
            firstName: 'Jane',
            lastName: 'Doe',
            institutions: ['Max Planck Institute'],
        },
        {
            orcid: '0000-0003-4567-8901',
            firstName: 'Test',
            lastName: 'User',
            institutions: [],
        },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
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
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        it('displays dialog title', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText('Search for ORCID')).toBeInTheDocument();
        });

        it('displays dialog description', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText(/search for orcid records by name/i)).toBeInTheDocument();
        });

        it('shows initial empty state before search', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByText(/enter a search query to find orcid records/i)).toBeInTheDocument();
        });
    });

    describe('search input', () => {
        it('renders search input field', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByLabelText(/search query/i)).toBeInTheDocument();
        });

        it('allows typing in search input', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'John Smith');

            expect(screen.getByLabelText(/search query/i)).toHaveValue('John Smith');
        });

        it('disables search button when query is empty', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByRole('button', { name: /^search$/i })).toBeDisabled();
        });

        it('enables search button when query has content', async () => {
            const user = userEvent.setup();
            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');

            expect(screen.getByRole('button', { name: /^search$/i })).not.toBeDisabled();
        });
    });

    describe('search functionality', () => {
        it('calls OrcidService.searchOrcid when search button is clicked', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], totalResults: 0, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'John');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            expect(OrcidService.searchOrcid).toHaveBeenCalledWith('John', 20);
        });

        it('triggers search on Enter key press', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], totalResults: 0, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'Jane{Enter}');

            expect(OrcidService.searchOrcid).toHaveBeenCalledWith('Jane', 20);
        });

        it('shows loading state while searching', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockImplementation(
                () =>
                    new Promise((resolve) => {
                        // Never resolves to keep loading state
                        setTimeout(() => resolve({ success: true, data: { results: [], totalResults: 0, itemsPerPage: 20 } }), 10000);
                    }),
            );

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            expect(screen.getByText(/searching\.\.\./i)).toBeInTheDocument();
            expect(screen.getByText(/searching orcid database/i)).toBeInTheDocument();
        });

        it('shows no results message when search returns empty', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], totalResults: 0, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'nonexistent12345');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/no results found/i)).toBeInTheDocument();
            });
        });
    });

    describe('search results', () => {
        it('displays search results in a table', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText('Smith')).toBeInTheDocument();
                expect(screen.getByText('John')).toBeInTheDocument();
                expect(screen.getByText('0000-0001-2345-6789')).toBeInTheDocument();
            });
        });

        it('displays results count', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/found 3 results/i)).toBeInTheDocument();
            });
        });

        it('displays institutions for each result', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/GFZ German Research Centre/i)).toBeInTheDocument();
                expect(screen.getByText(/Max Planck Institute/i)).toBeInTheDocument();
            });
        });

        it('shows "No affiliations" for results without institutions', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [mockSearchResults[2]], totalResults: 1, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/no affiliations/i)).toBeInTheDocument();
            });
        });
    });

    describe('result selection', () => {
        it('calls onSelect with result when row is clicked', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText('Smith')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Smith'));

            expect(mockOnSelect).toHaveBeenCalledWith(mockSearchResults[0]);
        });

        it('closes dialog after selection', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText('Smith')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Smith'));

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });
        });

        it('resets dialog state after selection', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: mockSearchResults, totalResults: 3, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            // First search
            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText('Smith')).toBeInTheDocument();
            });

            await user.click(screen.getByText('Smith'));

            // Reopen dialog
            await user.click(screen.getByRole('button', { name: /search for orcid/i }));

            expect(screen.getByLabelText(/search query/i)).toHaveValue('');
            expect(screen.getByText(/enter a search query to find orcid records/i)).toBeInTheDocument();
        });
    });

    describe('error handling', () => {
        it('shows empty results on API error', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockRejectedValue(new Error('Network error'));

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/no results found/i)).toBeInTheDocument();
            });
        });

        it('handles unsuccessful response', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: false,
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                expect(screen.getByText(/no results found/i)).toBeInTheDocument();
            });
        });
    });

    describe('external links', () => {
        it('renders link to ORCID.org for each result', async () => {
            const user = userEvent.setup();
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [mockSearchResults[0]], totalResults: 1, itemsPerPage: 20 },
            });

            render(<OrcidSearchDialog onSelect={mockOnSelect} />);

            await user.click(screen.getByRole('button', { name: /search for orcid/i }));
            await user.type(screen.getByLabelText(/search query/i), 'test');
            await user.click(screen.getByRole('button', { name: /^search$/i }));

            await waitFor(() => {
                const link = screen.getByRole('link', { name: /view on orcid\.org/i });
                expect(link).toHaveAttribute('href', 'https://orcid.org/0000-0001-2345-6789');
                expect(link).toHaveAttribute('target', '_blank');
                expect(link).toHaveAttribute('rel', 'noopener noreferrer');
            });
        });
    });
});
