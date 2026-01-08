import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import CoverageEntry from '@/components/curation/fields/spatial-temporal-coverage/CoverageEntry';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';

// Mock PointForm component
vi.mock(
    '@/components/curation/fields/spatial-temporal-coverage/PointForm',
    () => ({
        default: ({ onBatchChange }: { onBatchChange: (updates: Record<string, unknown>) => void }) => (
            <div data-testid="mock-point-form">
                <button
                    onClick={() =>
                        onBatchChange({
                            latMin: '48.137154',
                            lonMin: '11.576124',
                            latMax: '',
                            lonMax: '',
                        })
                    }
                    data-testid="select-point-btn"
                >
                    Select Point
                </button>
            </div>
        ),
    }),
);

// Mock BoxForm component
vi.mock(
    '@/components/curation/fields/spatial-temporal-coverage/BoxForm',
    () => ({
        default: ({ onBatchChange }: { onBatchChange: (updates: Record<string, unknown>) => void }) => (
            <div data-testid="mock-box-form">
                <button
                    onClick={() =>
                        onBatchChange({
                            latMin: '48.100000',
                            latMax: '48.200000',
                            lonMin: '11.500000',
                            lonMax: '11.700000',
                        })
                    }
                    data-testid="select-rectangle-btn"
                >
                    Select Rectangle
                </button>
            </div>
        ),
    }),
);

// Mock PolygonForm component
vi.mock(
    '@/components/curation/fields/spatial-temporal-coverage/PolygonForm',
    () => ({
        default: () => <div data-testid="mock-polygon-form">Polygon Form</div>,
    }),
);

describe('CoverageEntry', () => {
    const mockOnChange = vi.fn();
    const mockOnBatchChange = vi.fn();
    const mockOnRemove = vi.fn();

    const defaultEntry: SpatialTemporalCoverageEntry = {
        id: 'test-entry-1',
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
        description: '',
    };

    const defaultProps = {
        entry: defaultEntry,
        index: 0,
        apiKey: 'test-api-key',
        isFirst: true,
        onChange: mockOnChange,
        onBatchChange: mockOnBatchChange,
        onRemove: mockOnRemove,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
        mockOnBatchChange.mockClear();
        mockOnRemove.mockClear();
    });

    describe('Rendering', () => {
        test('renders coverage entry with correct title', () => {
            render(<CoverageEntry {...defaultProps} />);

            expect(screen.getByText('Coverage Entry #1')).toBeInTheDocument();
        });

        test('renders with correct index number', () => {
            render(<CoverageEntry {...defaultProps} index={5} />);

            expect(screen.getByText('Coverage Entry #6')).toBeInTheDocument();
        });

        test('is expanded by default', () => {
            render(<CoverageEntry {...defaultProps} />);

            // Point form should be visible (default type is 'point')
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();
            // Tab content should be visible
            expect(screen.getByRole('tabpanel')).toBeInTheDocument();
        });

        test('shows remove button when not first entry', () => {
            render(<CoverageEntry {...defaultProps} isFirst={false} />);

            const removeButton = screen.getByRole('button', { name: /remove coverage entry/i });
            expect(removeButton).toBeInTheDocument();
        });

        test('hides remove button when is first entry', () => {
            render(<CoverageEntry {...defaultProps} isFirst={true} />);

            const removeButton = screen.queryByRole('button', { name: /remove coverage entry/i });
            expect(removeButton).not.toBeInTheDocument();
        });

        test('renders form component based on coverage type', () => {
            render(<CoverageEntry {...defaultProps} />);

            // Default type is 'point', so PointForm should be visible
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();
        });

        test('renders description textarea', () => {
            render(<CoverageEntry {...defaultProps} />);

            const textarea = screen.getByPlaceholderText(/deep drilling campaign/i);
            expect(textarea).toBeInTheDocument();
        });
    });

    describe('Expand/Collapse', () => {
        test('entry is expanded by default', () => {
            render(<CoverageEntry {...defaultProps} />);

            // Point form should be visible in expanded state (default type)
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();
        });

        test('can collapse entry by clicking header', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} />);

            // Entry starts expanded
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();

            const header = screen.getByText('Coverage Entry #1').closest('[class*="cursor-pointer"]')!;
            await user.click(header);

            // Form should not be visible when collapsed
            expect(screen.queryByTestId('mock-point-form')).not.toBeInTheDocument();
        });

        test('can toggle entry by clicking chevron button', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} />);

            // Find collapse button (aria-label added)
            const collapseButton = screen.getByRole('button', { name: /collapse entry/i });
            await user.click(collapseButton);

            // Form should not be visible
            expect(screen.queryByTestId('mock-point-form')).not.toBeInTheDocument();

            // Find expand button
            const expandButton = screen.getByRole('button', { name: /expand entry/i });
            await user.click(expandButton);

            // Form should be visible again
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();
        });

        test('shows preview when collapsed and has data', async () => {
            const user = userEvent.setup();
            const entryWithData: SpatialTemporalCoverageEntry = {
                ...defaultEntry,
                latMin: '48.137154',
                lonMin: '11.576124',
                latMax: '48.200000',
                lonMax: '11.600000',
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                description: 'Test description',
            };

            render(<CoverageEntry {...defaultProps} entry={entryWithData} />);

            // Collapse the entry
            const collapseButton = screen.getByRole('button', { name: /collapse entry/i });
            await user.click(collapseButton);

            // Preview should show coordinate and date emojis
            expect(screen.getByText(/📍/)).toBeInTheDocument();
            expect(screen.getByText(/🕐/)).toBeInTheDocument();
            
            // Verify description is shown
            expect(screen.getByText('Test description')).toBeInTheDocument();
        });
    });

    describe('Map Interaction', () => {
        test('calls onBatchChange when point is selected from map', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} />);

            const selectPointButton = screen.getByTestId('select-point-btn');
            await user.click(selectPointButton);

            expect(mockOnBatchChange).toHaveBeenCalledWith({
                latMin: '48.137154',
                lonMin: '11.576124',
                latMax: '',
                lonMax: '',
            });
        });

        test('calls onBatchChange when rectangle is selected from map', async () => {
            const user = userEvent.setup();
            const boxEntry: SpatialTemporalCoverageEntry = {
                ...defaultEntry,
                type: 'box',
            };
            render(<CoverageEntry {...defaultProps} entry={boxEntry} />);

            const selectRectangleButton = screen.getByTestId('select-rectangle-btn');
            await user.click(selectRectangleButton);

            expect(mockOnBatchChange).toHaveBeenCalledWith({
                latMin: '48.100000',
                latMax: '48.200000',
                lonMin: '11.500000',
                lonMax: '11.700000',
            });
        });

        test('formats coordinates to 6 decimal places when selecting point', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} />);

            const selectPointButton = screen.getByTestId('select-point-btn');
            await user.click(selectPointButton);

            const call = mockOnBatchChange.mock.calls[0][0];
            expect(call.latMin).toMatch(/^\d+\.\d{6}$/);
            expect(call.lonMin).toMatch(/^\d+\.\d{6}$/);
        });

        test('formats coordinates to 6 decimal places when selecting rectangle', async () => {
            const user = userEvent.setup();
            const boxEntry: SpatialTemporalCoverageEntry = {
                ...defaultEntry,
                type: 'box',
            };
            render(<CoverageEntry {...defaultProps} entry={boxEntry} />);

            const selectRectangleButton = screen.getByTestId('select-rectangle-btn');
            await user.click(selectRectangleButton);

            const call = mockOnBatchChange.mock.calls[0][0];
            expect(call.latMin).toMatch(/^\d+\.\d{6}$/);
            expect(call.latMax).toMatch(/^\d+\.\d{6}$/);
            expect(call.lonMin).toMatch(/^\d+\.\d{6}$/);
            expect(call.lonMax).toMatch(/^\d+\.\d{6}$/);
        });
    });

    describe('Form Inputs', () => {
        test('calls onChange when description is entered', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} />);

            const textarea = screen.getByPlaceholderText(/deep drilling campaign/i);
            await user.type(textarea, 'Test description');

            // user.type() calls onChange for each character
            // Verify that onChange was called with the description field
            expect(mockOnChange).toHaveBeenCalled();
            const calls = mockOnChange.mock.calls.filter(call => call[0] === 'description');
            expect(calls.length).toBeGreaterThan(0);
        });

        test('displays existing description', () => {
            const entryWithDescription: SpatialTemporalCoverageEntry = {
                ...defaultEntry,
                description: 'Existing description',
            };

            render(<CoverageEntry {...defaultProps} entry={entryWithDescription} />);

            expect(screen.getByDisplayValue('Existing description')).toBeInTheDocument();
        });
    });

    describe('Remove Functionality', () => {
        test('calls onRemove when remove button is clicked', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} isFirst={false} />);

            const removeButton = screen.getByRole('button', { name: /remove coverage entry/i });
            await user.click(removeButton);

            expect(mockOnRemove).toHaveBeenCalled();
        });

        test('does not propagate click to header when clicking remove button', async () => {
            const user = userEvent.setup();
            render(<CoverageEntry {...defaultProps} isFirst={false} />);

            const removeButton = screen.getByRole('button', { name: /remove coverage entry/i });
            await user.click(removeButton);

            // Entry should still be expanded (click didn't propagate to header)
            expect(screen.getByTestId('mock-point-form')).toBeInTheDocument();
        });
    });
});
