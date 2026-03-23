import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalTemporalFilter } from '@/components/portal/PortalTemporalFilter';
import type { TemporalFilterValue, TemporalRange } from '@/types/portal';

describe('PortalTemporalFilter', () => {
    const defaultTemporalRange: TemporalRange = {
        Created: { min: 2000, max: 2024 },
        Collected: { min: 1995, max: 2023 },
    };

    const defaultProps = {
        enabled: false,
        onToggle: vi.fn(),
        temporalRange: defaultTemporalRange,
        temporal: null as TemporalFilterValue | null,
        onTemporalChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Disabled State', () => {
        it('renders the toggle switch and label', () => {
            render(<PortalTemporalFilter {...defaultProps} />);

            expect(screen.getByText('Temporal Filter')).toBeInTheDocument();
            expect(screen.getByLabelText(/enable temporal filter/i)).toBeInTheDocument();
        });

        it('does not show slider or tabs when disabled', () => {
            render(<PortalTemporalFilter {...defaultProps} />);

            expect(screen.queryByLabelText(/minimum year/i)).not.toBeInTheDocument();
            expect(screen.queryByLabelText(/maximum year/i)).not.toBeInTheDocument();
            expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        });

        it('does not show description text when disabled', () => {
            render(<PortalTemporalFilter {...defaultProps} />);

            expect(screen.queryByText(/when the data was created/i)).not.toBeInTheDocument();
        });
    });

    describe('Enabled State', () => {
        it('shows tabs when multiple date types are available', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.getByRole('tablist')).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /created/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /collected/i })).toBeInTheDocument();
        });

        it('does not show tabs when only one date type is available', () => {
            const singleRange: TemporalRange = { Created: { min: 2000, max: 2024 } };
            render(<PortalTemporalFilter {...defaultProps} enabled={true} temporalRange={singleRange} />);

            expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        });

        it('shows year inputs when enabled', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.getByLabelText(/minimum year/i)).toBeInTheDocument();
            expect(screen.getByLabelText(/maximum year/i)).toBeInTheDocument();
        });

        it('shows description text for selected date type', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.getByText(/when the data was created/i)).toBeInTheDocument();
        });

        it('shows slider thumbs when enabled', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.getByRole('slider', { name: /minimum/i })).toBeInTheDocument();
            expect(screen.getByRole('slider', { name: /maximum/i })).toBeInTheDocument();
        });

        it('initializes year inputs with range min/max', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            const minInput = screen.getByLabelText(/minimum year/i) as HTMLInputElement;
            const maxInput = screen.getByLabelText(/maximum year/i) as HTMLInputElement;

            expect(minInput.value).toBe('2000');
            expect(maxInput.value).toBe('2024');
        });
    });

    describe('Coverage Tab Visibility', () => {
        it('shows Coverage tab when Coverage data is in temporalRange', () => {
            const rangeWithCoverage: TemporalRange = {
                Created: { min: 2000, max: 2024 },
                Coverage: { min: 1990, max: 2020 },
            };
            render(<PortalTemporalFilter {...defaultProps} enabled={true} temporalRange={rangeWithCoverage} />);

            expect(screen.getByRole('tab', { name: /coverage/i })).toBeInTheDocument();
        });

        it('does not show Coverage tab when Coverage is not in temporalRange', () => {
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.queryByRole('tab', { name: /coverage/i })).not.toBeInTheDocument();
        });
    });

    describe('Toggle Behavior', () => {
        it('calls onToggle when switch is toggled on', async () => {
            const onToggle = vi.fn();
            const user = userEvent.setup();
            render(<PortalTemporalFilter {...defaultProps} onToggle={onToggle} />);

            await user.click(screen.getByRole('switch'));

            expect(onToggle).toHaveBeenCalledWith(true);
        });

        it('calls onToggle(false) and onTemporalChange(null) when toggled off', async () => {
            const onToggle = vi.fn();
            const onTemporalChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onToggle={onToggle}
                    onTemporalChange={onTemporalChange}
                />,
            );

            await user.click(screen.getByRole('switch'));

            expect(onToggle).toHaveBeenCalledWith(false);
            expect(onTemporalChange).toHaveBeenCalledWith(null);
        });
    });

    describe('Tab Switching', () => {
        it('switches description when tab is changed', async () => {
            const user = userEvent.setup();
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            expect(screen.getByText(/when the data was created/i)).toBeInTheDocument();

            await user.click(screen.getByRole('tab', { name: /collected/i }));

            expect(screen.getByText(/when the data was collected/i)).toBeInTheDocument();
        });

        it('updates year inputs when tab is changed', async () => {
            const user = userEvent.setup();
            render(<PortalTemporalFilter {...defaultProps} enabled={true} />);

            // Initially shows Created range (2000-2024)
            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('2000');
            expect((screen.getByLabelText(/maximum year/i) as HTMLInputElement).value).toBe('2024');

            await user.click(screen.getByRole('tab', { name: /collected/i }));

            // Should show Collected range (1995-2023)
            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('1995');
            expect((screen.getByLabelText(/maximum year/i) as HTMLInputElement).value).toBe('2023');
        });

        it('calls onTemporalChange when switching tabs', async () => {
            const onTemporalChange = vi.fn();
            const user = userEvent.setup();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            await user.click(screen.getByRole('tab', { name: /collected/i }));

            expect(onTemporalChange).toHaveBeenCalledWith({
                dateType: 'Collected',
                yearFrom: 1995,
                yearTo: 2023,
            });
        });
    });

    describe('Single Year Edge Case', () => {
        it('shows informational text instead of slider when min equals max', () => {
            const singleYearRange: TemporalRange = { Created: { min: 2020, max: 2020 } };
            render(<PortalTemporalFilter {...defaultProps} enabled={true} temporalRange={singleYearRange} />);

            expect(screen.getByText(/all records from 2020/i)).toBeInTheDocument();
            expect(screen.queryByLabelText(/minimum year/i)).not.toBeInTheDocument();
            expect(screen.queryByLabelText(/maximum year/i)).not.toBeInTheDocument();
        });
    });

    describe('Empty State', () => {
        it('returns null when no date types have data', () => {
            const { container } = render(
                <PortalTemporalFilter {...defaultProps} temporalRange={{}} />,
            );

            expect(container.innerHTML).toBe('');
        });
    });

    describe('Pre-selected Filter State', () => {
        it('initializes with temporal filter values from props', () => {
            const temporal: TemporalFilterValue = {
                dateType: 'Collected',
                yearFrom: 2005,
                yearTo: 2015,
            };
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={temporal}
                />,
            );

            const minInput = screen.getByLabelText(/minimum year/i) as HTMLInputElement;
            const maxInput = screen.getByLabelText(/maximum year/i) as HTMLInputElement;

            expect(minInput.value).toBe('2005');
            expect(maxInput.value).toBe('2015');
        });

        it('selects the correct tab based on temporal prop', () => {
            const temporal: TemporalFilterValue = {
                dateType: 'Collected',
                yearFrom: 2005,
                yearTo: 2015,
            };
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={temporal}
                />,
            );

            expect(screen.getByText(/when the data was collected/i)).toBeInTheDocument();
        });
    });

    describe('Year Input Handlers', () => {
        it('emits debounced change when yearFrom input is changed', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const minInput = screen.getByLabelText(/minimum year/i);
            fireEvent.change(minInput, { target: { value: '2010' } });

            // Should not have been called yet (debounced)
            expect(onTemporalChange).not.toHaveBeenCalled();

            // Advance past 300ms debounce
            act(() => {
                vi.advanceTimersByTime(350);
            });

            expect(onTemporalChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    dateType: 'Created',
                    yearFrom: 2010,
                }),
            );

            vi.useRealTimers();
        });

        it('emits debounced change when yearTo input is changed', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const maxInput = screen.getByLabelText(/maximum year/i);
            fireEvent.change(maxInput, { target: { value: '2020' } });

            act(() => {
                vi.advanceTimersByTime(350);
            });

            expect(onTemporalChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    dateType: 'Created',
                    yearTo: 2020,
                }),
            );

            vi.useRealTimers();
        });

        it('clamps yearFrom to not exceed yearTo', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const minInput = screen.getByLabelText(/minimum year/i);
            // yearTo is 2024, so 2025 should clamp to 2024
            fireEvent.change(minInput, { target: { value: '2025' } });

            act(() => {
                vi.advanceTimersByTime(350);
            });

            expect(onTemporalChange).toHaveBeenCalledWith(
                expect.objectContaining({ yearFrom: 2024 }),
            );

            vi.useRealTimers();
        });

        it('clamps yearTo to not be below yearFrom', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            const temporal: TemporalFilterValue = {
                dateType: 'Created',
                yearFrom: 2010,
                yearTo: 2024,
            };
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={temporal}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const maxInput = screen.getByLabelText(/maximum year/i);
            // yearFrom is 2010, so 2005 should clamp to 2010
            fireEvent.change(maxInput, { target: { value: '2005' } });

            act(() => {
                vi.advanceTimersByTime(350);
            });

            expect(onTemporalChange).toHaveBeenCalledWith(
                expect.objectContaining({ yearTo: 2010 }),
            );

            vi.useRealTimers();
        });

        it('ignores NaN input for yearFrom', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const minInput = screen.getByLabelText(/minimum year/i);
            fireEvent.change(minInput, { target: { value: 'abc' } });

            act(() => {
                vi.advanceTimersByTime(350);
            });

            // Should not have been called because NaN input is ignored
            expect(onTemporalChange).not.toHaveBeenCalled();

            vi.useRealTimers();
        });

        it('ignores NaN input for yearTo', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onTemporalChange={onTemporalChange}
                />,
            );

            const maxInput = screen.getByLabelText(/maximum year/i);
            fireEvent.change(maxInput, { target: { value: '' } });

            act(() => {
                vi.advanceTimersByTime(350);
            });

            expect(onTemporalChange).not.toHaveBeenCalled();

            vi.useRealTimers();
        });
    });

    describe('Toggle Clears Debounce', () => {
        it('cancels pending debounce and emits null when toggled off', () => {
            vi.useFakeTimers();
            const onTemporalChange = vi.fn();
            const onToggle = vi.fn();
            render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    onToggle={onToggle}
                    onTemporalChange={onTemporalChange}
                />,
            );

            // Type in yearFrom to trigger debounce via fireEvent
            const minInput = screen.getByLabelText(/minimum year/i);
            fireEvent.change(minInput, { target: { value: '2010' } });

            // Toggle off before debounce fires
            fireEvent.click(screen.getByRole('switch'));

            // The debounced change should NOT fire, only null from toggle
            act(() => {
                vi.advanceTimersByTime(500);
            });

            expect(onToggle).toHaveBeenCalledWith(false);
            // onTemporalChange should have been called with null (from toggle off), not with the debounced value
            expect(onTemporalChange).toHaveBeenCalledWith(null);

            vi.useRealTimers();
        });
    });

    describe('Sync Effect', () => {
        it('syncs local state when temporal prop changes', () => {
            const { rerender } = render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                />,
            );

            // Initially shows Created range defaults
            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('2000');

            // Rerender with a temporal prop
            const temporal: TemporalFilterValue = {
                dateType: 'Created',
                yearFrom: 2005,
                yearTo: 2018,
            };
            rerender(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={temporal}
                />,
            );

            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('2005');
            expect((screen.getByLabelText(/maximum year/i) as HTMLInputElement).value).toBe('2018');
        });

        it('resets to range defaults when temporal is cleared', () => {
            const temporal: TemporalFilterValue = {
                dateType: 'Created',
                yearFrom: 2005,
                yearTo: 2018,
            };
            const { rerender } = render(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={temporal}
                />,
            );

            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('2005');

            // Clear temporal
            rerender(
                <PortalTemporalFilter
                    {...defaultProps}
                    enabled={true}
                    temporal={null}
                />,
            );

            // Should reset to Created range min/max
            expect((screen.getByLabelText(/minimum year/i) as HTMLInputElement).value).toBe('2000');
            expect((screen.getByLabelText(/maximum year/i) as HTMLInputElement).value).toBe('2024');
        });
    });
});
