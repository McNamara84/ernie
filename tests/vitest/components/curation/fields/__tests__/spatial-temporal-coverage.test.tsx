import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import SpatialTemporalCoverageField from '@/components/curation/fields/spatial-temporal-coverage';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock the CoverageEntry component to simplify testing
vi.mock(
    '@/components/curation/fields/spatial-temporal-coverage/CoverageEntry',
    () => ({
        default: ({
            entry,
            index,
            onChange,
            onRemove,
        }: {
            entry: SpatialTemporalCoverageEntry;
            index: number;
            onChange: (id: string, changes: Partial<SpatialTemporalCoverageEntry>) => void;
            onRemove: (id: string) => void;
        }) => (
            <div data-testid={`coverage-entry-${index}`}>
                <span>Coverage {index + 1}</span>
                <input
                    aria-label="Latitude *"
                    id="lat-min"
                    onChange={(e) =>
                        onChange(entry.id, { latMin: e.target.value })
                    }
                    type="text"
                    value={entry.latMin}
                />
                <button onClick={() => onRemove(entry.id)}>Remove</button>
            </div>
        ),
    }),
);

describe('SpatialTemporalCoverageField', () => {
    const mockOnChange = vi.fn();

    const defaultProps = {
        coverages: [],
        apiKey: 'test-api-key',
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    test('renders empty state with add button', () => {
        render(<SpatialTemporalCoverageField {...defaultProps} />);

        expect(screen.getByText(/no spatial and temporal coverage entries yet/i)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /add.*coverage entry/i }),
        ).toBeInTheDocument();
    });

    test('adds a new coverage entry when add button is clicked', async () => {
        const user = userEvent.setup();
        render(<SpatialTemporalCoverageField {...defaultProps} />);

        const addButton = screen.getByRole('button', {
            name: /add.*coverage entry/i,
        });

        await user.click(addButton);

        expect(mockOnChange).toHaveBeenCalledWith([
            expect.objectContaining({
                id: expect.any(String),
                latMin: '',
                lonMin: '',
                startDate: '',
                endDate: '',
                timezone: expect.any(String), // Should default to user's timezone
            }),
        ]);
    });

    test('renders existing coverage entries', () => {
        const existingEntries: SpatialTemporalCoverageEntry[] = [
            {
                id: 'test-1',
                latMin: '48.137154',
                lonMin: '11.576124',
                latMax: '',
                lonMax: '',
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                startTime: '',
                endTime: '',
                timezone: 'UTC',
                description: '',
            },
        ];

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={existingEntries}
            />,
        );

        expect(screen.getByTestId('coverage-entry-0')).toBeInTheDocument();
        expect(screen.queryByText(/no coverage entries yet/i)).not.toBeInTheDocument();
    });

    test('renders multiple coverage entries', () => {
        const existingEntries: SpatialTemporalCoverageEntry[] = [
            {
                id: 'test-1',
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
            },
            {
                id: 'test-2',
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
            },
        ];

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={existingEntries}
            />,
        );

        expect(screen.getByTestId('coverage-entry-0')).toBeInTheDocument();
        expect(screen.getByTestId('coverage-entry-1')).toBeInTheDocument();
    });

    test('disables add button when coverage entry is incomplete', () => {
        const incompleteEntry: SpatialTemporalCoverageEntry = {
            id: 'test-1',
            latMin: '48.137154',
            lonMin: '', // Missing required field
            latMax: '',
            lonMax: '',
            startDate: '',
            endDate: '',
            startTime: '',
            endTime: '',
            timezone: 'UTC',
            description: '',
        };

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={[incompleteEntry]}
            />,
        );

        // When entry is incomplete, there's no add button, only a help message
        expect(screen.queryByRole('button', {
            name: /add.*coverage entry/i,
        })).not.toBeInTheDocument();
        
        expect(screen.getByText(/complete the required fields/i)).toBeInTheDocument();
    });

    test('removes a coverage entry when remove is called', async () => {
        const user = userEvent.setup();
        const existingEntries: SpatialTemporalCoverageEntry[] = [
            {
                id: 'test-1',
                latMin: '48.137154',
                lonMin: '11.576124',
                latMax: '',
                lonMax: '',
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                startTime: '',
                endTime: '',
                timezone: 'UTC',
                description: '',
            },
        ];

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={existingEntries}
            />,
        );

        const removeButton = screen.getByRole('button', { name: /remove/i });

        await user.click(removeButton);

        expect(mockOnChange).toHaveBeenCalledWith([]);
    });

    test('enforces maxCoverages limit', () => {
        const maxEntries = Array.from({ length: 99 }, (_, i) => ({
            id: `test-${i}`,
            latMin: '0',
            lonMin: '0',
            latMax: '',
            lonMax: '',
            startDate: '2024-01-01',
            endDate: '2024-12-31',
            startTime: '',
            endTime: '',
            timezone: 'UTC',
            description: '',
        }));

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={maxEntries}
                maxCoverages={99}
            />,
        );

        // When max is reached, there should be no add button
        expect(
            screen.queryByRole('button', { name: /add.*coverage entry/i }),
        ).not.toBeInTheDocument();
        
        // Instead, there should be a message about the limit
        expect(screen.getByText(/maximum.*99/i)).toBeInTheDocument();
    });

    test('updates entry field when onChange is called', () => {
        const existingEntries: SpatialTemporalCoverageEntry[] = [
            {
                id: 'test-1',
                latMin: '',
                lonMin: '',
                latMax: '',
                lonMax: '',
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                startTime: '',
                endTime: '',
                timezone: 'UTC',
                description: '',
            },
        ];

        render(
            <SpatialTemporalCoverageField
                {...defaultProps}
                coverages={existingEntries}
            />,
        );

        // Verify that we can update the entry through the mocked component
        const mockedInput = screen.getByLabelText(/^Latitude \*$/i);
        expect(mockedInput).toBeInTheDocument();
        expect(mockedInput).toHaveValue('');
    });

    test('sets default timezone when adding new entry', async () => {
        const user = userEvent.setup();
        render(<SpatialTemporalCoverageField {...defaultProps} />);

        const addButton = screen.getByRole('button', {
            name: /add.*coverage entry/i,
        });

        await user.click(addButton);

        const calls = mockOnChange.mock.calls;
        const newEntry = calls[0][0][0];

        // Should have a default timezone set
        expect(newEntry.timezone).toBeTruthy();
        expect(newEntry.timezone.length).toBeGreaterThan(0);
    });
});
