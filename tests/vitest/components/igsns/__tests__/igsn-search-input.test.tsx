import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { IgsnSearchInput } from '@/components/igsns/igsn-search-input';

describe('IgsnSearchInput', () => {
    let user: ReturnType<typeof userEvent.setup>;

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const defaultProps = {
        value: '',
        onChange: vi.fn(),
        resultCount: 10,
        totalCount: 10,
        isLoading: false,
    };

    // ========================================================================
    // Rendering
    // ========================================================================

    describe('rendering', () => {
        it('renders the search input with correct placeholder', () => {
            render(<IgsnSearchInput {...defaultProps} />);
            expect(screen.getByPlaceholderText(/Search IGSN or title/)).toBeInTheDocument();
        });

        it('renders the search input with correct aria-label', () => {
            render(<IgsnSearchInput {...defaultProps} />);
            expect(screen.getByLabelText('Search IGSNs by IGSN or title')).toBeInTheDocument();
        });

        it('renders with the provided value', () => {
            render(<IgsnSearchInput {...defaultProps} value="test query" />);
            expect(screen.getByLabelText('Search IGSNs by IGSN or title')).toHaveValue('test query');
        });

        it('disables the input when isLoading is true', () => {
            render(<IgsnSearchInput {...defaultProps} isLoading={true} />);
            expect(screen.getByLabelText('Search IGSNs by IGSN or title')).toBeDisabled();
        });

        it('enables the input when isLoading is false', () => {
            render(<IgsnSearchInput {...defaultProps} isLoading={false} />);
            expect(screen.getByLabelText('Search IGSNs by IGSN or title')).toBeEnabled();
        });
    });

    // ========================================================================
    // Total / Filtered Count Display
    // ========================================================================

    describe('count display', () => {
        it('shows total count when not filtered', () => {
            render(<IgsnSearchInput {...defaultProps} resultCount={25} totalCount={25} />);
            expect(screen.getByText('25')).toBeInTheDocument();
            expect(screen.getByText(/samples total/)).toBeInTheDocument();
        });

        it('shows filtered count when resultCount differs from totalCount', () => {
            render(<IgsnSearchInput {...defaultProps} resultCount={5} totalCount={25} />);
            expect(screen.getByText('5')).toBeInTheDocument();
            expect(screen.getByText('25')).toBeInTheDocument();
            expect(screen.getByText(/Showing/)).toBeInTheDocument();
        });

        it('does not show "Showing" text when not filtered', () => {
            render(<IgsnSearchInput {...defaultProps} resultCount={10} totalCount={10} />);
            expect(screen.queryByText(/Showing/)).not.toBeInTheDocument();
        });
    });

    // ========================================================================
    // Debounce Behavior
    // ========================================================================

    describe('debounce', () => {
        it('does not call onChange immediately when typing', async () => {
            const onChange = vi.fn();
            render(<IgsnSearchInput {...defaultProps} onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            await user.type(input, 'rock');

            // onChange should NOT have been called yet (debounce pending)
            expect(onChange).not.toHaveBeenCalled();
        });

        it('calls onChange after debounce delay for input >= 3 characters', async () => {
            const onChange = vi.fn();
            render(<IgsnSearchInput {...defaultProps} onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            await user.type(input, 'rock');

            // Advance past the 1000ms debounce
            act(() => {
                vi.advanceTimersByTime(1000);
            });

            expect(onChange).toHaveBeenCalledTimes(1);
            expect(onChange).toHaveBeenCalledWith('rock');
        });

        it('does not call onChange for input shorter than 3 characters', async () => {
            const onChange = vi.fn();
            render(<IgsnSearchInput {...defaultProps} onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            await user.type(input, 'ab');

            act(() => {
                vi.advanceTimersByTime(1500);
            });

            expect(onChange).not.toHaveBeenCalled();
        });

        it('resets the debounce timer on each keystroke (rapid typing)', async () => {
            const onChange = vi.fn();
            render(<IgsnSearchInput {...defaultProps} onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');

            // Type 'r', wait 500ms, type 'o', wait 500ms, type 'c', wait 500ms, type 'k'
            await user.type(input, 'r');
            act(() => { vi.advanceTimersByTime(500); });

            await user.type(input, 'o');
            act(() => { vi.advanceTimersByTime(500); });

            await user.type(input, 'c');
            act(() => { vi.advanceTimersByTime(500); });

            await user.type(input, 'k');

            // 500ms after last keystroke — should NOT have fired yet
            act(() => { vi.advanceTimersByTime(500); });
            expect(onChange).not.toHaveBeenCalled();

            // After the remaining 500ms (1000ms total from last keystroke)
            act(() => { vi.advanceTimersByTime(500); });
            expect(onChange).toHaveBeenCalledTimes(1);
            expect(onChange).toHaveBeenCalledWith('rock');
        });
    });

    // ========================================================================
    // Clearing Input
    // ========================================================================

    describe('clearing', () => {
        it('calls onChange immediately with empty string when input is cleared', async () => {
            const onChange = vi.fn();
            render(<IgsnSearchInput {...defaultProps} value="rock" onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            await user.clear(input);

            // Should fire immediately (no debounce for clearing)
            expect(onChange).toHaveBeenCalledTimes(1);
            expect(onChange).toHaveBeenCalledWith('');
        });
    });

    // ========================================================================
    // Sync with External Value
    // ========================================================================

    describe('external value sync', () => {
        it('syncs input with external value prop changes', () => {
            const { rerender } = render(<IgsnSearchInput {...defaultProps} value="" />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            expect(input).toHaveValue('');

            rerender(<IgsnSearchInput {...defaultProps} value="new search" />);
            expect(input).toHaveValue('new search');
        });
    });

    // ========================================================================
    // Focus Management
    // ========================================================================

    describe('focus management', () => {
        it('restores focus after loading completes when search is active', () => {
            const { rerender } = render(<IgsnSearchInput {...defaultProps} value="rock sample" isLoading={true} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            expect(input).not.toHaveFocus();

            // Finish loading
            rerender(<IgsnSearchInput {...defaultProps} value="rock sample" isLoading={false} />);

            // Focus is restored after a 100ms timeout
            act(() => { vi.advanceTimersByTime(150); });
            expect(input).toHaveFocus();
        });

        it('does not restore focus when search input is short', () => {
            const { rerender } = render(<IgsnSearchInput {...defaultProps} value="ab" isLoading={true} />);

            rerender(<IgsnSearchInput {...defaultProps} value="ab" isLoading={false} />);

            act(() => { vi.advanceTimersByTime(150); });

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            expect(input).not.toHaveFocus();
        });
    });

    // ========================================================================
    // Cleanup on Unmount
    // ========================================================================

    describe('cleanup', () => {
        it('does not call onChange after unmount', async () => {
            const onChange = vi.fn();
            const { unmount } = render(<IgsnSearchInput {...defaultProps} onChange={onChange} />);

            const input = screen.getByLabelText('Search IGSNs by IGSN or title');
            await user.type(input, 'rock');

            // Unmount before debounce fires
            unmount();

            act(() => {
                vi.advanceTimersByTime(1500);
            });

            // The timeout was cleared on unmount, so onChange should NOT have been called
            expect(onChange).not.toHaveBeenCalled();
        });
    });
});
