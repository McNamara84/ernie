import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import PointForm from '@/components/curation/fields/spatial-temporal-coverage/PointForm';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock the MapPicker component since it requires Google Maps API
vi.mock('@/components/curation/fields/spatial-temporal-coverage/MapPicker', () => ({
    default: ({ onPointSelected, latMin, lonMin }: { onPointSelected: (lat: number, lng: number) => void; latMin: string; lonMin: string }) => (
        <div data-testid="map-picker">
            <span data-testid="map-lat">{latMin}</span>
            <span data-testid="map-lon">{lonMin}</span>
            <button data-testid="select-point" onClick={() => onPointSelected(52.123456, 13.654321)}>
                Select Point
            </button>
        </div>
    ),
}));

// Mock CoordinateInputs
vi.mock('@/components/curation/fields/spatial-temporal-coverage/CoordinateInputs', () => ({
    default: ({
        latMin,
        lonMin,
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
            <input data-testid="lat-input" value={latMin} onChange={(e) => onChange('latMin', e.target.value)} />
            <input data-testid="lon-input" value={lonMin} onChange={(e) => onChange('lonMin', e.target.value)} />
        </div>
    ),
}));

describe('PointForm', () => {
    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: '1',
        geoType: 'Point',
        description: 'Test point',
        latMin: '52.5200',
        lonMin: '13.4050',
        latMax: '',
        lonMax: '',
        startDate: '',
        endDate: '',
        polygon: [],
    };

    const defaultProps = {
        entry: defaultEntry,
        apiKey: 'test-api-key',
        onChange: vi.fn(),
        onBatchChange: vi.fn(),
    };

    it('renders MapPicker and CoordinateInputs components', () => {
        render(<PointForm {...defaultProps} />);

        expect(screen.getByTestId('map-picker')).toBeInTheDocument();
        expect(screen.getByTestId('coordinate-inputs')).toBeInTheDocument();
    });

    it('passes correct coordinates to MapPicker', () => {
        render(<PointForm {...defaultProps} />);

        expect(screen.getByTestId('map-lat')).toHaveTextContent('52.5200');
        expect(screen.getByTestId('map-lon')).toHaveTextContent('13.4050');
    });

    it('passes correct coordinates to CoordinateInputs', () => {
        render(<PointForm {...defaultProps} />);

        expect(screen.getByTestId('lat-input')).toHaveValue('52.5200');
        expect(screen.getByTestId('lon-input')).toHaveValue('13.4050');
    });

    it('calls onBatchChange when point is selected on map', async () => {
        const onBatchChange = vi.fn();
        render(<PointForm {...defaultProps} onBatchChange={onBatchChange} />);

        const selectButton = screen.getByTestId('select-point');
        selectButton.click();

        expect(onBatchChange).toHaveBeenCalledWith({
            latMin: '52.123456',
            lonMin: '13.654321',
            latMax: '',
            lonMax: '',
        });
    });

    it('provides onChange handler to CoordinateInputs', () => {
        const onChange = vi.fn();
        render(<PointForm {...defaultProps} onChange={onChange} />);

        // The CoordinateInputs component receives the onChange handler
        expect(screen.getByTestId('coordinate-inputs')).toBeInTheDocument();
    });

    it('renders with empty coordinates', () => {
        const emptyEntry: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            latMin: '',
            lonMin: '',
        };

        render(<PointForm {...defaultProps} entry={emptyEntry} />);

        expect(screen.getByTestId('lat-input')).toHaveValue('');
        expect(screen.getByTestId('lon-input')).toHaveValue('');
    });

    it('has correct grid layout classes', () => {
        const { container } = render(<PointForm {...defaultProps} />);

        const gridDiv = container.querySelector('.grid');
        expect(gridDiv).toHaveClass('grid-cols-1', 'lg:grid-cols-2', 'gap-6');
    });
});
