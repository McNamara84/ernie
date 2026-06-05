import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import CoverageEntry from '@/components/curation/fields/spatial-temporal-coverage/CoverageEntry';
import type { CoverageType, SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

vi.mock('@/components/curation/fields/spatial-temporal-coverage/MapPicker', () => ({
    default: ({
        onRectangleSelected,
    }: {
        onRectangleSelected: (bounds: { south: number; north: number; west: number; east: number }) => void;
    }) => (
        <div data-testid="map-picker">
            <button
                type="button"
                onClick={() => onRectangleSelected({ south: 51, north: 53, west: 12, east: 14 })}
            >
                Select Rectangle
            </button>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/PolygonForm', () => ({
    default: () => <div data-testid="polygon-form">Polygon Form</div>,
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/LineForm', () => ({
    default: () => <div data-testid="line-form">Line Form</div>,
}));

describe('CoverageEntry global bounding box shortcut', () => {
    const createEntry = (type: CoverageType): SpatialTemporalCoverageEntry => ({
        id: `coverage-${type}`,
        type,
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
        polygonPoints: type === 'polygon' || type === 'line' ? [] : undefined,
    });

    const defaultProps = {
        index: 0,
        apiKey: 'test-api-key',
        isFirst: true,
        onChange: vi.fn(),
        onBatchChange: vi.fn(),
        onRemove: vi.fn(),
        initiallyExpanded: true,
    };

    it('shows the Global button in the Bounding Box tab', () => {
        render(<CoverageEntry {...defaultProps} entry={createEntry('box')} />);

        expect(screen.getByRole('tab', { name: /bounding box/i })).toHaveAttribute('data-state', 'active');
        expect(screen.getByRole('button', { name: /global/i })).toBeInTheDocument();
    });

    it('passes global coverage coordinates through CoverageEntry when Global is clicked', async () => {
        const user = userEvent.setup();
        const onBatchChange = vi.fn();
        render(<CoverageEntry {...defaultProps} entry={createEntry('box')} onBatchChange={onBatchChange} />);

        await user.click(screen.getByRole('button', { name: /global/i }));

        expect(onBatchChange).toHaveBeenCalledWith({
            latMin: '-90.000000',
            latMax: '90.000000',
            lonMin: '-180.000000',
            lonMax: '180.000000',
        });
    });

    it.each([
        ['point', /point/i],
        ['polygon', /polygon/i],
        ['line', /line/i],
    ] as const)('does not show the Global button in the %s tab', (type, tabName) => {
        render(<CoverageEntry {...defaultProps} entry={createEntry(type)} />);

        expect(screen.getByRole('tab', { name: tabName })).toHaveAttribute('data-state', 'active');
        expect(screen.queryByRole('button', { name: /global/i })).not.toBeInTheDocument();
    });
});
