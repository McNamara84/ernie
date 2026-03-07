import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import LineForm from '@/components/curation/fields/spatial-temporal-coverage/LineForm';
import type { PolygonPoint, SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock Google Maps API components
vi.mock('@vis.gl/react-google-maps', () => ({
    APIProvider: ({ children }: { children: React.ReactNode }) => <div data-testid="api-provider">{children}</div>,
    Map: ({ children }: { children: React.ReactNode }) => <div data-testid="google-map">{children}</div>,
    useMap: () => null,
}));

// Mock CoordinateCsvImport to simulate import behavior
vi.mock('@/components/curation/fields/spatial-temporal-coverage/coordinate-csv-import', () => ({
    default: ({
        onImport,
        onClose,
        existingPointCount,
        geoType,
    }: {
        onImport: (points: PolygonPoint[], mode: 'replace' | 'append') => void;
        onClose: () => void;
        existingPointCount: number;
        geoType: string;
    }) => {
        return (
            <div data-testid="csv-import-mock">
                <span data-testid="csv-existing-count">{existingPointCount}</span>
                <span data-testid="csv-geo-type">{geoType}</span>
                <button data-testid="csv-import-replace" onClick={() => { onImport([{ lat: 10, lon: 20 }, { lat: 30, lon: 40 }], 'replace'); onClose(); }}>
                    Import Replace
                </button>
                <button data-testid="csv-import-append" onClick={() => { onImport([{ lat: 70, lon: 80 }], 'append'); onClose(); }}>
                    Import Append
                </button>
                <button data-testid="csv-close" onClick={onClose}>
                    Close
                </button>
            </div>
        );
    },
}));

describe('LineForm', () => {
    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: '1',
        type: 'line',
        description: '',
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: 'UTC',
        polygonPoints: [],
    };

    const defaultProps = {
        entry: defaultEntry,
        apiKey: 'test-api-key',
        onChange: vi.fn(),
        onBatchChange: vi.fn(),
    };

    it('renders CSV Import button', () => {
        render(<LineForm {...defaultProps} />);
        expect(screen.getByRole('button', { name: /csv import/i })).toBeInTheDocument();
    });

    it('opens CSV Import dialog when button is clicked', async () => {
        const user = userEvent.setup();
        render(<LineForm {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /csv import/i }));

        expect(screen.getByText('Import Line Coordinates from CSV')).toBeInTheDocument();
        expect(screen.getByTestId('csv-import-mock')).toBeInTheDocument();
    });

    it('passes geoType="line" to CoordinateCsvImport', async () => {
        const user = userEvent.setup();
        render(<LineForm {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /csv import/i }));

        expect(screen.getByTestId('csv-geo-type')).toHaveTextContent('line');
    });

    it('passes existing point count to CoordinateCsvImport', async () => {
        const user = userEvent.setup();
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 1, lon: 2 },
                { lat: 3, lon: 4 },
            ],
        };

        render(<LineForm {...defaultProps} entry={entryWithPoints} />);
        await user.click(screen.getByRole('button', { name: /csv import/i }));

        expect(screen.getByTestId('csv-existing-count')).toHaveTextContent('2');
    });

    it('calls onBatchChange with replaced points on replace import', async () => {
        const user = userEvent.setup();
        const onBatchChange = vi.fn();
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [{ lat: 99, lon: 99 }],
        };

        render(<LineForm {...defaultProps} entry={entryWithPoints} onBatchChange={onBatchChange} />);
        await user.click(screen.getByRole('button', { name: /csv import/i }));
        await user.click(screen.getByTestId('csv-import-replace'));

        expect(onBatchChange).toHaveBeenCalledWith({
            polygonPoints: [
                { lat: 10, lon: 20 },
                { lat: 30, lon: 40 },
            ],
        });
    });

    it('calls onBatchChange with appended points on append import', async () => {
        const user = userEvent.setup();
        const onBatchChange = vi.fn();
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 1, lon: 2 },
                { lat: 3, lon: 4 },
            ],
        };

        render(<LineForm {...defaultProps} entry={entryWithPoints} onBatchChange={onBatchChange} />);
        await user.click(screen.getByRole('button', { name: /csv import/i }));
        await user.click(screen.getByTestId('csv-import-append'));

        expect(onBatchChange).toHaveBeenCalledWith({
            polygonPoints: [
                { lat: 1, lon: 2 },
                { lat: 3, lon: 4 },
                { lat: 70, lon: 80 },
            ],
        });
    });

    it('deduplicates boundary point when last existing equals first imported on append', async () => {
        const user = userEvent.setup();
        const onBatchChange = vi.fn();
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 1, lon: 2 },
                { lat: 70, lon: 80 },
            ],
        };

        render(<LineForm {...defaultProps} entry={entryWithPoints} onBatchChange={onBatchChange} />);
        await user.click(screen.getByRole('button', { name: /csv import/i }));
        await user.click(screen.getByTestId('csv-import-append'));

        expect(onBatchChange).toHaveBeenCalledWith({
            polygonPoints: [
                { lat: 1, lon: 2 },
                { lat: 70, lon: 80 },
            ],
        });
    });

    it('closes CSV Import dialog after import', async () => {
        const user = userEvent.setup();
        render(<LineForm {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /csv import/i }));
        expect(screen.getByTestId('csv-import-mock')).toBeInTheDocument();

        await user.click(screen.getByTestId('csv-import-replace'));

        expect(screen.queryByTestId('csv-import-mock')).not.toBeInTheDocument();
    });

    it('renders empty state when no points exist', () => {
        render(<LineForm {...defaultProps} />);
        expect(screen.getByText('No points yet')).toBeInTheDocument();
    });

    it('renders points table when points exist', () => {
        const entryWithPoints: SpatialTemporalCoverageEntry = {
            ...defaultEntry,
            polygonPoints: [
                { lat: 52.52, lon: 13.405 },
                { lat: 48.8566, lon: 2.3522 },
            ],
        };

        render(<LineForm {...defaultProps} entry={entryWithPoints} />);
        expect(screen.getByText('Line Points (2)')).toBeInTheDocument();
    });
});
