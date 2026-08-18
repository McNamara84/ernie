import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import CoverageEntry from '@/components/curation/fields/spatial-temporal-coverage/CoverageEntry';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

vi.mock('@/components/curation/fields/spatial-temporal-coverage/PointForm', () => ({
    default: () => <div>Point form</div>,
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/BoxForm', () => ({
    default: () => <div>Box form</div>,
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/PolygonForm', () => ({
    default: () => <div>Polygon form</div>,
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/LineForm', () => ({
    default: () => <div>Line form</div>,
}));

vi.mock('@/components/curation/fields/spatial-temporal-coverage/TemporalInputs', () => ({
    default: () => <div>Temporal inputs</div>,
}));

describe('CoverageEntry collapsed preview', () => {
    it('keeps long descriptions shrinkable and preserves the full value when expanded', async () => {
        const user = userEvent.setup();
        const description =
            'SPG (Saint Petersburg) maintained by the Saint Petersburg branch of the Pushkov Institute of Terrestrial Magnetism, Ionosphere and Radio Wave Propagation of the Russian Academy of Sciences '.repeat(
                4,
            ) + 'https://example.org/locations/this-is-a-single-very-long-unbroken-segment-that-must-not-expand-the-editor';
        const entry: SpatialTemporalCoverageEntry = {
            id: 'coverage-overflow-regression',
            type: 'point',
            latMin: '',
            lonMin: '',
            latMax: '',
            lonMax: '',
            startDate: '',
            endDate: '',
            startTime: '',
            endTime: '',
            timezone: 'UTC',
            description,
        };

        render(
            <CoverageEntry
                entry={entry}
                index={0}
                apiKey="test-api-key"
                isFirst={true}
                onChange={vi.fn()}
                onBatchChange={vi.fn()}
                onRemove={vi.fn()}
                initiallyExpanded={false}
            />,
        );

        const preview = screen.getByText(description);
        expect(preview).toHaveClass('line-clamp-1', 'min-w-0', '[overflow-wrap:anywhere]');
        expect(preview).not.toHaveClass('truncate');

        await user.click(screen.getByRole('button', { name: 'Expand entry' }));

        expect(screen.getByLabelText('Description (optional)')).toHaveValue(description);
    });
});
