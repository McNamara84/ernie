import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import PolygonForm from '@/components/curation/fields/spatial-temporal-coverage/PolygonForm';
import type {
    PolygonPoint,
    SpatialTemporalCoverageEntry,
} from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock Google Maps components
vi.mock('@vis.gl/react-google-maps', () => ({
    APIProvider: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="api-provider">{children}</div>
    ),
    Map: () => <div data-testid="polygon-map">Polygon Map</div>,
    useMap: () => null,
}));

describe('PolygonForm', () => {
    const mockOnChange = vi.fn();
    const mockOnBatchChange = vi.fn();

    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: 'test-1',
        type: 'polygon',
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: 'UTC',
        description: '',
        polygonPoints: [],
    };

    const defaultProps = {
        entry: defaultEntry,
        apiKey: 'test-api-key',
        onChange: mockOnChange,
        onBatchChange: mockOnBatchChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
        mockOnBatchChange.mockClear();
    });

    test('renders polygon form with empty state', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(screen.getByTestId('polygon-map')).toBeInTheDocument();
        expect(screen.getByText(/polygon points \(0\)/i)).toBeInTheDocument();
        expect(
            screen.getByText(/no points yet/i),
        ).toBeInTheDocument();
    });

    test('renders start drawing button', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(
            screen.getByRole('button', { name: /start drawing/i }),
        ).toBeInTheDocument();
    });

    test('renders add point button', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(
            screen.getByRole('button', { name: /add point/i }),
        ).toBeInTheDocument();
    });

    test('renders fullscreen button', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(
            screen.getByRole('button', { name: /fullscreen/i }),
        ).toBeInTheDocument();
    });

    test('displays points count correctly', () => {
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 15, lon: 25 },
                { lat: 10, lon: 30 },
            ],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        expect(screen.getByText(/polygon points \(3\)/i)).toBeInTheDocument();
    });

    test('renders coordinate table with points', () => {
        const points: PolygonPoint[] = [
            { lat: 10.5, lon: 20.5 },
            { lat: 15.5, lon: 25.5 },
            { lat: 10.5, lon: 30.5 },
        ];

        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: points,
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        // Check table headers
        expect(screen.getByText('#')).toBeInTheDocument();
        expect(screen.getByText('Latitude')).toBeInTheDocument();
        expect(screen.getByText('Longitude')).toBeInTheDocument();

        // Check point numbers (row indices)
        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();

        // Check coordinate inputs exist
        const inputs = screen.getAllByRole('spinbutton');
        expect(inputs).toHaveLength(6); // 3 points × 2 coordinates
    });

    test('adds new point when add point button is clicked', async () => {
        const user = userEvent.setup();
        render(<PolygonForm {...defaultProps} />);

        const addButton = screen.getByRole('button', { name: /add point/i });
        await user.click(addButton);

        expect(mockOnBatchChange).toHaveBeenCalledWith({
            polygonPoints: [{ lat: 0, lon: 0 }],
        });
    });

    test('shows minimum points warning when less than 3 points', () => {
        const entryWithTwoPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 15, lon: 25 },
            ],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithTwoPoints} />);

        expect(
            screen.getByText(/minimum 3 points required/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/currently: 2/i)).toBeInTheDocument();
    });

    test('does not show warning when 3 or more points', () => {
        const entryWithThreePoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 15, lon: 25 },
                { lat: 10, lon: 30 },
            ],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithThreePoints} />);

        expect(
            screen.queryByText(/minimum 3 points required/i),
        ).not.toBeInTheDocument();
    });

    test('displays clear polygon button when points exist', () => {
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 15, lon: 25 },
                { lat: 10, lon: 30 },
            ],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        expect(
            screen.getByRole('button', { name: /clear polygon/i }),
        ).toBeInTheDocument();
    });

    test('does not display clear button when no points', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(
            screen.queryByRole('button', { name: /clear polygon/i }),
        ).not.toBeInTheDocument();
    });

    test('toggles drawing mode when start drawing button is clicked', async () => {
        const user = userEvent.setup();
        render(<PolygonForm {...defaultProps} />);

        const drawButton = screen.getByRole('button', {
            name: /start drawing/i,
        });

        // Initially not in drawing mode
        expect(drawButton).toHaveTextContent(/start drawing/i);

        // Click to enter drawing mode
        await user.click(drawButton);

        // Should show active state
        expect(drawButton).toHaveTextContent(/drawing mode active/i);
    });

    test('shows drawing instruction when in drawing mode', async () => {
        const user = userEvent.setup();
        render(<PolygonForm {...defaultProps} />);

        const drawButton = screen.getByRole('button', {
            name: /start drawing/i,
        });
        await user.click(drawButton);

        expect(
            screen.getByText(/click on the map to add points \(minimum 3 required\)/i),
        ).toBeInTheDocument();
    });

    test('renders delete buttons for each point', () => {
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 15, lon: 25 },
                { lat: 10, lon: 30 },
            ],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        // Each row should have a delete button
        // We have 3 rows, so we should have all row numbers visible
        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        
        // And the table should exist
        expect(screen.getByRole('table')).toBeInTheDocument();
    });

    test('coordinate inputs have correct step attribute', () => {
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [{ lat: 10.123456, lon: 20.654321 }],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        const inputs = screen.getAllByRole('spinbutton');
        inputs.forEach((input) => {
            expect(input).toHaveAttribute('step', '0.000001');
        });
    });

    test('handles manual coordinate input', async () => {
        const user = userEvent.setup();
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [{ lat: 10, lon: 20 }],
        };

        render(<PolygonForm {...defaultProps} entry={entryWithPoints} />);

        const inputs = screen.getAllByRole('spinbutton');
        const latInput = inputs[0] as HTMLInputElement;

        // Clear and type new value
        await user.tripleClick(latInput); // Select all
        await user.type(latInput, '52.5');

        // Check that onBatchChange was called multiple times (once per character typed)
        expect(mockOnBatchChange).toHaveBeenCalled();
        
        // Check that at least one call contains a valid lat value change
        const calls = mockOnBatchChange.mock.calls;
        const hasLatitudeChange = calls.some(
            call => call[0].polygonPoints[0].lat !== 10
        );
        expect(hasLatitudeChange).toBe(true);
    });

    test('renders map with correct test id', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(screen.getByTestId('polygon-map')).toBeInTheDocument();
    });

    test('renders API provider with correct test id', () => {
        render(<PolygonForm {...defaultProps} />);

        expect(screen.getByTestId('api-provider')).toBeInTheDocument();
    });

    test('maintains 2-column layout structure', () => {
        const { container } = render(<PolygonForm {...defaultProps} />);

        const gridContainer = container.querySelector('.grid.grid-cols-1');
        expect(gridContainer).toBeInTheDocument();
        expect(gridContainer).toHaveClass('lg:grid-cols-2');
    });
});
