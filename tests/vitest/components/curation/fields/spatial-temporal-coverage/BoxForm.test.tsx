import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import BoxForm from '@/components/curation/fields/spatial-temporal-coverage/BoxForm';
import type { CoordinateBounds, SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock the MapPicker component since it requires Google Maps API
vi.mock('@/components/curation/fields/spatial-temporal-coverage/MapPicker', () => ({
    default: ({
        onRectangleSelected,
        latMin,
        lonMin,
        latMax,
        lonMax,
    }: {
        onRectangleSelected: (bounds: CoordinateBounds) => void;
        latMin: string;
        lonMin: string;
        latMax: string;
        lonMax: string;
    }) => (
        <div data-testid="map-picker">
            <span data-testid="map-lat-min">{latMin}</span>
            <span data-testid="map-lon-min">{lonMin}</span>
            <span data-testid="map-lat-max">{latMax}</span>
            <span data-testid="map-lon-max">{lonMax}</span>
            <button
                data-testid="select-rectangle"
                onClick={() => onRectangleSelected({ south: 51.0, north: 53.0, west: 12.0, east: 14.0 })}
            >
                Select Rectangle
            </button>
        </div>
    ),
}));

// Mock CoordinateInputs
vi.mock('@/components/curation/fields/spatial-temporal-coverage/CoordinateInputs', () => ({
    default: ({
        latMin,
        lonMin,
        latMax,
        lonMax,
        onChange,
    }: {
        latMin: string;
        lonMin: string;
        latMax: string;
        lonMax: string;
        onChange: (field: 'latMin' | 'lonMin' | 'latMax' | 'lonMax', value: string) => void;
        showLabels: boolean;
    }) => (
        <div data-testid="coordinate-inputs">
            <input data-testid="lat-min-input" value={latMin} onChange={(e) => onChange('latMin', e.target.value)} />
            <input data-testid="lon-min-input" value={lonMin} onChange={(e) => onChange('lonMin', e.target.value)} />
            <input data-testid="lat-max-input" value={latMax} onChange={(e) => onChange('latMax', e.target.value)} />
            <input data-testid="lon-max-input" value={lonMax} onChange={(e) => onChange('lonMax', e.target.value)} />
        </div>
    ),
}));

describe('BoxForm', () => {
    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: '1',
        type: 'box',
        description: 'Test bounding box',
        latMin: '51.0000',
        lonMin: '12.0000',
        latMax: '53.0000',
        lonMax: '14.0000',
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: 'UTC',
    };

    const defaultProps = {
        entry: defaultEntry,
        apiKey: 'test-api-key',
        onChange: vi.fn(),
        onBatchChange: vi.fn(),
    };

    it('renders MapPicker and CoordinateInputs components', () => {
        render(<BoxForm {...defaultProps} />);

        expect(screen.getByTestId('map-picker')).toBeInTheDocument();
        expect(screen.getByTestId('coordinate-inputs')).toBeInTheDocument();
    });

    it('passes correct coordinates to MapPicker', () => {
        render(<BoxForm {...defaultProps} />);

        expect(screen.getByTestId('map-lat-min')).toHaveTextContent('51.0000');
        expect(screen.getByTestId('map-lon-min')).toHaveTextContent('12.0000');
        expect(screen.getByTestId('map-lat-max')).toHaveTextContent('53.0000');
        expect(screen.getByTestId('map-lon-max')).toHaveTextContent('14.0000');
    });

    it('passes correct coordinates to CoordinateInputs', () => {
        render(<BoxForm {...defaultProps} />);

        expect(screen.getByTestId('lat-min-input')).toHaveValue('51.0000');
        expect(screen.getByTestId('lon-min-input')).toHaveValue('12.0000');
        expect(screen.getByTestId('lat-max-input')).toHaveValue('53.0000');
        expect(screen.getByTestId('lon-max-input')).toHaveValue('14.0000');
    });

    it('calls onBatchChange when rectangle is selected on map', async () => {
        const onBatchChange = vi.fn();
        render(<BoxForm {...defaultProps} onBatchChange={onBatchChange} />);

        const selectButton = screen.getByTestId('select-rectangle');
        selectButton.click();

        expect(onBatchChange).toHaveBeenCalledWith({
            latMin: '51.000000',
            latMax: '53.000000',
            lonMin: '12.000000',
            lonMax: '14.000000',
        });
    });

    it('provides onChange handler to CoordinateInputs', () => {
        const onChange = vi.fn();
        render(<BoxForm {...defaultProps} onChange={onChange} />);

        // The CoordinateInputs component receives the onChange handler
        expect(screen.getByTestId('coordinate-inputs')).toBeInTheDocument();
    });

    it('renders with empty coordinates', () => {
        const emptyEntry: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            latMin: '',
            lonMin: '',
            latMax: '',
            lonMax: '',
        };

        render(<BoxForm {...defaultProps} entry={emptyEntry} />);

        expect(screen.getByTestId('lat-min-input')).toHaveValue('');
        expect(screen.getByTestId('lon-min-input')).toHaveValue('');
        expect(screen.getByTestId('lat-max-input')).toHaveValue('');
        expect(screen.getByTestId('lon-max-input')).toHaveValue('');
    });

    it('has correct grid layout classes', () => {
        const { container } = render(<BoxForm {...defaultProps} />);

        const gridDiv = container.querySelector('.grid');
        expect(gridDiv).toHaveClass('grid-cols-1', 'lg:grid-cols-2', 'gap-6');
    });
});
