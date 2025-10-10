import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import CoverageEntry from '@/components/curation/fields/spatial-temporal-coverage/CoverageEntry';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock the MapPicker component to avoid Google Maps API dependency
vi.mock(
    '@/components/curation/fields/spatial-temporal-coverage/MapPicker',
    () => ({
        default: ({
            onPointSelected,
            onRectangleSelected,
        }: {
            onPointSelected: (lat: number, lon: number) => void;
            onRectangleSelected: (bounds: {
                north: number;
                south: number;
                east: number;
                west: number;
            }) => void;
        }) => (
            <div data-testid="mock-map-picker">
                <button
                    onClick={() => onPointSelected?.(48.137154, 11.576124)}
                >
                    Select Point
                </button>
                <button
                    onClick={() =>
                        onRectangleSelected?.({
                            north: 48.15,
                            south: 48.13,
                            east: 11.6,
                            west: 11.55,
                        })
                    }
                >
                    Select Rectangle
                </button>
            </div>
        ),
    }),
);

describe('CoverageEntry', () => {
    const mockOnChange = vi.fn();
    const mockOnRemove = vi.fn();

    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: 'test-1',
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: '',
        description: '',
    };

    const mockOnBatchChange = vi.fn();

    const defaultProps = {
        entry: defaultEntry,
        index: 0,
        apiKey: 'test-api-key',
        isFirst: false,
        onChange: mockOnChange,
        onBatchChange: mockOnBatchChange,
        onRemove: mockOnRemove,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
        mockOnRemove.mockClear();
        mockOnBatchChange.mockClear();
    });

    test('renders the coverage entry', () => {
        render(<CoverageEntry {...defaultProps} />);

        expect(
            screen.getByRole('button', { name: /coverage 1/i }),
        ).toBeInTheDocument();
    });

    test('expands and collapses when clicked', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        const trigger = screen.getByRole('button', { name: /coverage 1/i });

        // Initially collapsed - map should not be visible
        expect(screen.queryByTestId('mock-map-picker')).not.toBeInTheDocument();

        // Click to expand
        await user.click(trigger);

        // Now expanded - map should be visible
        expect(screen.getByTestId('mock-map-picker')).toBeInTheDocument();

        // Click to collapse again
        await user.click(trigger);

        // Should be collapsed again
        expect(screen.queryByTestId('mock-map-picker')).not.toBeInTheDocument();
    });

    test('calls onBatchChange when point is selected on map', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        // Expand to see the map
        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        // Click the Select Point button in the mocked map
        await user.click(screen.getByText('Select Point'));

        // Should call onBatchChange with all coordinate fields updated at once
        expect(mockOnBatchChange).toHaveBeenCalledWith({
            latMin: '48.137154',
            lonMin: '11.576124',
            latMax: '',
            lonMax: '',
        });
    });

    test('calls onBatchChange when rectangle is selected on map', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        // Expand to see the map
        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        // Click the Select Rectangle button in the mocked map
        await user.click(screen.getByText('Select Rectangle'));

        // Should call onBatchChange with all coordinate fields updated at once
        expect(mockOnBatchChange).toHaveBeenCalledWith({
            latMin: '48.13',
            lonMin: '11.55',
            latMax: '48.15',
            lonMax: '11.6',
        });
    });

    test('shows preview of coordinates when collapsed', () => {
        const entry: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            latMin: '48.137154',
            lonMin: '11.576124',
            latMax: '48.150000',
            lonMax: '11.600000',
        };

        render(<CoverageEntry {...defaultProps} entry={entry} />);

        // Should show min coordinates in preview
        expect(screen.getByText(/48\.137154/)).toBeInTheDocument();
        expect(screen.getByText(/11\.576124/)).toBeInTheDocument();
    });

    test('shows remove button and calls onRemove when clicked', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        // Expand to see the remove button
        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        const removeButton = screen.getByRole('button', {
            name: /remove/i,
        });

        expect(removeButton).toBeInTheDocument();

        await user.click(removeButton);

        expect(mockOnRemove).toHaveBeenCalledTimes(1);
    });

    test('calls onChange when description is changed', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        // Expand to see the description
        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        const descriptionInput = screen.getByLabelText(/description/i);

        await user.type(descriptionInput, 'Test description');

        // Check that onChange was called (typing triggers multiple onChange events)
        expect(mockOnChange).toHaveBeenCalled();
        const calls = mockOnChange.mock.calls;
        const lastCall = calls[calls.length - 1];
        expect(lastCall[0]).toBe('description');
        expect(lastCall[1]).toContain('Test description');
    });

    test('renders coordinate inputs section', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        expect(screen.getByText(/^Coordinates$/i)).toBeInTheDocument();
    });

    test('renders temporal inputs section', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        expect(screen.getByText(/^Temporal Information$/i)).toBeInTheDocument();
    });

    test('renders description section', async () => {
        const user = userEvent.setup();
        render(<CoverageEntry {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /coverage 1/i }));

        expect(screen.getByLabelText(/^Description \(optional\)$/i)).toBeInTheDocument();
    });
});
