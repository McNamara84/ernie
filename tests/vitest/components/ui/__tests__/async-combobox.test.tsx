import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { AsyncCombobox, type AsyncComboboxOption } from '@/components/ui/async-combobox';

const mockOptions: AsyncComboboxOption[] = [
    { value: 'gfz', label: 'GFZ Potsdam', data: { id: 1 } },
    { value: 'mit', label: 'MIT', data: { id: 2 } },
    { value: 'eth', label: 'ETH Zurich', data: { id: 3 } },
];

const mockSearch = vi.fn<(query: string) => Promise<AsyncComboboxOption[]>>();

describe('AsyncCombobox', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mockSearch.mockReset();
        mockSearch.mockResolvedValue(mockOptions);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders with placeholder', () => {
        render(<AsyncCombobox onSearch={mockSearch} placeholder="Search institutions..." />);
        expect(screen.getByRole('combobox')).toBeInTheDocument();
        expect(screen.getByText('Search institutions...')).toBeInTheDocument();
    });

    it('renders disabled state', () => {
        render(<AsyncCombobox onSearch={mockSearch} disabled />);
        expect(screen.getByRole('combobox')).toBeDisabled();
    });

    it('renders error state', () => {
        render(<AsyncCombobox onSearch={mockSearch} error />);
        expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
    });

    it('renders required state', () => {
        render(<AsyncCombobox onSearch={mockSearch} required />);
        expect(screen.getByRole('combobox')).toHaveAttribute('aria-required', 'true');
    });

    it('opens popover on click', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<AsyncCombobox onSearch={mockSearch} />);

        const trigger = screen.getByRole('combobox');
        await user.click(trigger);

        expect(screen.getByPlaceholderText('Type to search...')).toBeInTheDocument();
    });

    it('shows minimum chars message before typing', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<AsyncCombobox onSearch={mockSearch} minSearchLength={2} />);

        await user.click(screen.getByRole('combobox'));

        expect(screen.getByText(/Type at least 2 characters to search/)).toBeInTheDocument();
    });

    it('triggers search after debounce', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<AsyncCombobox onSearch={mockSearch} debounceMs={300} />);

        await user.click(screen.getByRole('combobox'));
        await user.type(screen.getByPlaceholderText('Type to search...'), 'GFZ');

        // Advance past debounce
        await act(async () => {
            vi.advanceTimersByTime(350);
        });

        await waitFor(() => {
            expect(mockSearch).toHaveBeenCalledWith('GFZ');
        });
    });

    it('displays search results', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<AsyncCombobox onSearch={mockSearch} debounceMs={100} />);

        await user.click(screen.getByRole('combobox'));
        await user.type(screen.getByPlaceholderText('Type to search...'), 'G');

        await act(async () => {
            vi.advanceTimersByTime(150);
        });

        await waitFor(() => {
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });
    });

    it('calls onChange on selection (single mode)', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const onChange = vi.fn();

        render(<AsyncCombobox onSearch={mockSearch} onChange={onChange} debounceMs={100} />);

        await user.click(screen.getByRole('combobox'));
        await user.type(screen.getByPlaceholderText('Type to search...'), 'G');

        await act(async () => {
            vi.advanceTimersByTime(150);
        });

        await waitFor(() => {
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });

        await user.click(screen.getByText('GFZ Potsdam'));

        expect(onChange).toHaveBeenCalledWith('gfz', expect.objectContaining({ value: 'gfz', label: 'GFZ Potsdam' }));
    });

    it('shows selected value', () => {
        render(
            <AsyncCombobox
                onSearch={mockSearch}
                value="gfz"
                selectedOption={{ value: 'gfz', label: 'GFZ Potsdam' }}
            />,
        );

        expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
    });

    it('shows clear button when value is selected', () => {
        render(
            <AsyncCombobox
                onSearch={mockSearch}
                value="gfz"
                selectedOption={{ value: 'gfz', label: 'GFZ Potsdam' }}
                clearable
            />,
        );

        expect(screen.getByLabelText('Clear selection')).toBeInTheDocument();
    });

    it('clears value on clear button click', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const onChange = vi.fn();

        render(
            <AsyncCombobox
                onSearch={mockSearch}
                value="gfz"
                selectedOption={{ value: 'gfz', label: 'GFZ Potsdam' }}
                onChange={onChange}
                clearable
            />,
        );

        await user.click(screen.getByLabelText('Clear selection'));
        expect(onChange).toHaveBeenCalledWith(undefined, undefined);
    });

    it('renders hidden input for form submission', () => {
        const { container } = render(
            <AsyncCombobox
                onSearch={mockSearch}
                name="institution"
                value="gfz"
                selectedOption={{ value: 'gfz', label: 'GFZ Potsdam' }}
            />,
        );

        const hiddenInput = container.querySelector('input[type="hidden"][name="institution"]');
        expect(hiddenInput).toBeInTheDocument();
        expect(hiddenInput).toHaveValue('gfz');
    });

    describe('multi-select mode', () => {
        it('renders badges for selected values', () => {
            render(
                <AsyncCombobox
                    onSearch={mockSearch}
                    multiple
                    values={['gfz', 'mit']}
                    selectedOptions={[
                        { value: 'gfz', label: 'GFZ Potsdam' },
                        { value: 'mit', label: 'MIT' },
                    ]}
                />,
            );

            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
            expect(screen.getByText('MIT')).toBeInTheDocument();
        });

        it('shows +N more badge when exceeding maxDisplayItems', () => {
            render(
                <AsyncCombobox
                    onSearch={mockSearch}
                    multiple
                    maxDisplayItems={1}
                    values={['gfz', 'mit', 'eth']}
                    selectedOptions={[
                        { value: 'gfz', label: 'GFZ Potsdam' },
                        { value: 'mit', label: 'MIT' },
                        { value: 'eth', label: 'ETH Zurich' },
                    ]}
                />,
            );

            expect(screen.getByText('+2 more')).toBeInTheDocument();
        });

        it('renders hidden inputs for form submission in multi mode', () => {
            const { container } = render(
                <AsyncCombobox
                    onSearch={mockSearch}
                    multiple
                    name="institutions"
                    values={['gfz', 'mit']}
                    selectedOptions={[
                        { value: 'gfz', label: 'GFZ Potsdam' },
                        { value: 'mit', label: 'MIT' },
                    ]}
                />,
            );

            const hiddenInputs = container.querySelectorAll('input[type="hidden"][name="institutions[]"]');
            expect(hiddenInputs).toHaveLength(2);
        });

        it('calls onValuesChange when clearing all in multi mode', async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            const onValuesChange = vi.fn();

            render(
                <AsyncCombobox
                    onSearch={mockSearch}
                    multiple
                    values={['gfz']}
                    selectedOptions={[{ value: 'gfz', label: 'GFZ Potsdam' }]}
                    onValuesChange={onValuesChange}
                    clearable
                />,
            );

            await user.click(screen.getByLabelText('Clear selection'));
            expect(onValuesChange).toHaveBeenCalledWith([], []);
        });
    });

    it('handles search errors gracefully', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        mockSearch.mockRejectedValue(new Error('Network error'));

        render(<AsyncCombobox onSearch={mockSearch} debounceMs={100} />);

        await user.click(screen.getByRole('combobox'));
        await user.type(screen.getByPlaceholderText('Type to search...'), 'test');

        await act(async () => {
            vi.advanceTimersByTime(150);
        });

        await waitFor(() => {
            expect(consoleSpy).toHaveBeenCalled();
        });

        consoleSpy.mockRestore();
    });

    it('shows empty message when no results found', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        mockSearch.mockResolvedValue([]);

        render(<AsyncCombobox onSearch={mockSearch} debounceMs={100} emptyMessage="Nothing found" />);

        await user.click(screen.getByRole('combobox'));
        await user.type(screen.getByPlaceholderText('Type to search...'), 'xyz');

        await act(async () => {
            vi.advanceTimersByTime(150);
        });

        await waitFor(() => {
            expect(screen.getByText('Nothing found')).toBeInTheDocument();
        });
    });
});
