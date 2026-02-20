import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { FundingReferenceField } from '@/components/curation/fields/funding-reference/funding-reference-field';
import type { FundingReferenceEntry, RorFunder } from '@/components/curation/fields/funding-reference/types';

// Mock DnD kit
vi.mock('@dnd-kit/core', () => ({
    DndContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    closestCenter: vi.fn(),
    KeyboardSensor: vi.fn(),
    PointerSensor: vi.fn(),
    useSensor: vi.fn(() => ({})),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    SortableContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    sortableKeyboardCoordinates: vi.fn(),
    verticalListSortingStrategy: 'vertical',
}));

const mockLoadRorFunders = vi.fn<() => Promise<RorFunder[]>>();
const mockGetFunderByRorId = vi.fn<(funders: RorFunder[], rorId: string) => RorFunder | undefined>();

vi.mock('@/components/curation/fields/funding-reference/ror-search', () => ({
    loadRorFunders: (...args: Parameters<typeof mockLoadRorFunders>) => mockLoadRorFunders(...args),
    getFunderByRorId: (...args: Parameters<typeof mockGetFunderByRorId>) => mockGetFunderByRorId(...args),
}));

// Mock SortableFundingReferenceItem
vi.mock('@/components/curation/fields/funding-reference/sortable-funding-reference-item', () => ({
    SortableFundingReferenceItem: ({
        funding,
        index,
        onFunderNameChange,
        onAwardNumberChange,
        onRemove,
    }: {
        funding: FundingReferenceEntry;
        index: number;
        onFunderNameChange: (val: string) => void;
        onAwardNumberChange: (val: string) => void;
        onRemove: () => void;
    }) => (
        <div data-testid={`funding-item-${index}`}>
            <span data-testid={`funder-name-${index}`}>{funding.funderName}</span>
            <span data-testid={`award-number-${index}`}>{funding.awardNumber}</span>
            <button data-testid={`change-name-${index}`} onClick={() => onFunderNameChange('Updated Funder')}>
                Change Name
            </button>
            <button data-testid={`change-award-${index}`} onClick={() => onAwardNumberChange('AWARD-123')}>
                Change Award
            </button>
            <button data-testid={`remove-funding-${index}`} onClick={onRemove}>
                Remove
            </button>
        </div>
    ),
}));

const createFunding = (overrides: Partial<FundingReferenceEntry> = {}): FundingReferenceEntry => ({
    id: `funding-${Date.now()}-${Math.random().toString(36).substring(7)}`,
    funderName: '',
    funderIdentifier: '',
    funderIdentifierType: null,
    awardNumber: '',
    awardUri: '',
    awardTitle: '',
    isExpanded: false,
    ...overrides,
});

describe('FundingReferenceField', () => {
    let onChange: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        onChange = vi.fn();
        vi.clearAllMocks();
        mockLoadRorFunders.mockResolvedValue([]);
    });

    describe('empty state', () => {
        it('shows empty state when no funding references', async () => {
            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByText(/no funding references added/i)).toBeInTheDocument();
            });
        });

        it('shows descriptive text in empty state', async () => {
            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByText(/add information about grants/i)).toBeInTheDocument();
            });
        });

        it('shows the counter as 0 / 99', async () => {
            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByText(/0 \/ 99/i)).toBeInTheDocument();
            });
        });
    });

    describe('loading state', () => {
        it('shows loading ROR data message while loading', () => {
            mockLoadRorFunders.mockImplementation(() => new Promise(() => {})); // Never resolves

            render(<FundingReferenceField value={[]} onChange={onChange} />);

            expect(screen.getByText(/loading ror data/i)).toBeInTheDocument();
        });

        it('hides loading message after ROR data loads', async () => {
            mockLoadRorFunders.mockResolvedValue([]);

            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });
        });
    });

    describe('add/remove', () => {
        it('adds a new funding reference when Add button is clicked', async () => {
            const user = userEvent.setup();

            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            const addButtons = screen.getAllByRole('button', { name: /add funding reference/i });
            await user.click(addButtons[0]);

            expect(onChange).toHaveBeenCalledWith([
                expect.objectContaining({
                    funderName: '',
                    awardNumber: '',
                    isExpanded: false,
                }),
            ]);
        });

        it('disables add button when maximum is reached', async () => {
            const maxFundings = Array.from({ length: 99 }, (_, i) =>
                createFunding({ id: `f-${i}`, funderName: `Funder ${i}` }),
            );

            render(<FundingReferenceField value={maxFundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            const addButton = screen.getByRole('button', { name: /add funding reference/i });
            expect(addButton).toBeDisabled();
            expect(addButton).toHaveTextContent(/maximum reached/i);
        });

        it('removes a funding reference', async () => {
            const user = userEvent.setup();
            const fundings = [
                createFunding({ id: 'f1', funderName: 'DFG' }),
                createFunding({ id: 'f2', funderName: 'EU' }),
            ];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            await user.click(screen.getByTestId('remove-funding-0'));

            expect(onChange).toHaveBeenCalledWith([
                expect.objectContaining({ id: 'f2', funderName: 'EU' }),
            ]);
        });
    });

    describe('field changes', () => {
        it('updates funder name via handleFieldChange', async () => {
            const user = userEvent.setup();
            const fundings = [createFunding({ id: 'f1', funderName: 'Old Name' })];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            await user.click(screen.getByTestId('change-name-0'));

            expect(onChange).toHaveBeenCalledWith([
                expect.objectContaining({ id: 'f1', funderName: 'Updated Funder' }),
            ]);
        });

        it('updates award number via handleFieldChange', async () => {
            const user = userEvent.setup();
            const fundings = [createFunding({ id: 'f1' })];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            await user.click(screen.getByTestId('change-award-0'));

            expect(onChange).toHaveBeenCalledWith([
                expect.objectContaining({ id: 'f1', awardNumber: 'AWARD-123' }),
            ]);
        });
    });

    describe('counter display', () => {
        it('shows singular text for 1 reference', async () => {
            const fundings = [createFunding({ id: 'f1' })];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByText('1 / 99 funding reference')).toBeInTheDocument();
            });
        });

        it('shows plural text for multiple references', async () => {
            const fundings = [
                createFunding({ id: 'f1' }),
                createFunding({ id: 'f2' }),
            ];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByText('2 / 99 funding references')).toBeInTheDocument();
            });
        });
    });

    describe('ROR auto-fill', () => {
        it('auto-fills funder name from ROR data when funderIdentifier is set', async () => {
            const rorFunders: RorFunder[] = [
                { prefLabel: 'Deutsche Forschungsgemeinschaft', rorId: 'https://ror.org/018mejw64', otherLabel: ['DFG'] },
            ];
            mockLoadRorFunders.mockResolvedValue(rorFunders);
            mockGetFunderByRorId.mockReturnValue(rorFunders[0]);

            const fundings = [
                createFunding({
                    id: 'f1',
                    funderName: '', // empty — should be auto-filled
                    funderIdentifier: 'https://ror.org/018mejw64',
                    funderIdentifierType: 'ROR',
                }),
            ];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(onChange).toHaveBeenCalledWith([
                    expect.objectContaining({
                        funderName: 'Deutsche Forschungsgemeinschaft',
                        funderIdentifier: 'https://ror.org/018mejw64',
                    }),
                ]);
            });
        });

        it('does not overwrite existing funder names', async () => {
            const rorFunders: RorFunder[] = [
                { prefLabel: 'Deutsche Forschungsgemeinschaft', rorId: 'https://ror.org/018mejw64', otherLabel: [] },
            ];
            mockLoadRorFunders.mockResolvedValue(rorFunders);

            const fundings = [
                createFunding({
                    id: 'f1',
                    funderName: 'Already Set', // Should NOT be overwritten
                    funderIdentifier: 'https://ror.org/018mejw64',
                    funderIdentifierType: 'ROR',
                }),
            ];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            // Wait for ROR loading to complete
            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            // onChange should NOT be called for auto-fill since name is already set
            // The only onChange calls would be from auto-fill, and they shouldn't happen
            expect(onChange).not.toHaveBeenCalled();
        });

        it('handles ROR loading errors gracefully', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockLoadRorFunders.mockRejectedValue(new Error('Network error'));

            render(<FundingReferenceField value={[]} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.queryByText(/loading ror data/i)).not.toBeInTheDocument();
            });

            consoleSpy.mockRestore();
        });
    });

    describe('list rendering', () => {
        it('renders funding items when value is provided', async () => {
            const fundings = [
                createFunding({ id: 'f1', funderName: 'DFG', awardNumber: 'ABC-1' }),
                createFunding({ id: 'f2', funderName: 'EU', awardNumber: 'ERC-2' }),
            ];

            render(<FundingReferenceField value={fundings} onChange={onChange} />);

            await waitFor(() => {
                expect(screen.getByTestId('funding-item-0')).toBeInTheDocument();
                expect(screen.getByTestId('funding-item-1')).toBeInTheDocument();
            });

            expect(screen.getByTestId('funder-name-0')).toHaveTextContent('DFG');
            expect(screen.getByTestId('funder-name-1')).toHaveTextContent('EU');
        });
    });
});
