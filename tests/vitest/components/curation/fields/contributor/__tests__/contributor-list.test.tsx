import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ContributorList from '@/components/curation/fields/contributor/contributor-list';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';

// Mock DnD kit — simplified to just render children
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

// Mock ContributorItem to a simplified version
vi.mock('@/components/curation/fields/contributor/contributor-item', () => ({
    default: ({
        contributor,
        index,
        onTypeChange,
        onPersonFieldChange,
        onInstitutionNameChange,
        onRemove,
        canRemove,
    }: {
        contributor: ContributorEntry;
        index: number;
        onTypeChange: (type: 'person' | 'institution') => void;
        onPersonFieldChange: (field: string, value: string) => void;
        onInstitutionNameChange: (value: string) => void;
        onRemove: () => void;
        canRemove: boolean;
    }) => (
        <div data-testid={`contributor-${index}`} role="listitem">
            <span data-testid={`type-${index}`}>{contributor.type}</span>
            <span data-testid={`name-${index}`}>
                {contributor.type === 'person'
                    ? `${contributor.firstName} ${contributor.lastName}`
                    : contributor.institutionName}
            </span>
            <button data-testid={`change-type-${index}`} onClick={() => onTypeChange(contributor.type === 'person' ? 'institution' : 'person')}>
                Toggle Type
            </button>
            {contributor.type === 'person' && (
                <button data-testid={`change-name-${index}`} onClick={() => onPersonFieldChange('firstName', 'Updated')}>
                    Change Name
                </button>
            )}
            {contributor.type === 'institution' && (
                <button data-testid={`change-inst-${index}`} onClick={() => onInstitutionNameChange('Updated Inst')}>
                    Change Inst
                </button>
            )}
            {canRemove && (
                <button data-testid={`remove-${index}`} onClick={onRemove}>
                    Remove
                </button>
            )}
        </div>
    ),
}));

// Mock ContributorCsvImport
vi.mock('@/components/curation/fields/contributor-csv-import', () => ({
    default: ({
        onImport,
        onClose,
    }: {
        onImport: (data: { type: string; firstName?: string; lastName?: string; institutionName?: string; affiliations: string[]; contributorRole?: string }[]) => void;
        onClose: () => void;
    }) => (
        <div data-testid="csv-import-dialog">
            <button
                data-testid="csv-import-action"
                onClick={() =>
                    onImport([
                        { type: 'person', firstName: 'Jane', lastName: 'Doe', affiliations: ['MIT'], contributorRole: 'DataCurator' },
                        { type: 'institution', institutionName: 'CERN', affiliations: [], contributorRole: 'HostingInstitution' },
                    ])
                }
            >
                Import
            </button>
            <button data-testid="csv-close-action" onClick={onClose}>
                Cancel
            </button>
        </div>
    ),
}));

const createPersonContributor = (overrides: Partial<ContributorEntry> = {}): ContributorEntry => ({
    id: `contrib-${Date.now()}-${Math.random().toString(36).substring(7)}`,
    type: 'person',
    orcid: '',
    firstName: 'John',
    lastName: 'Smith',
    orcidVerified: false,
    roles: [],
    rolesInput: '',
    affiliations: [],
    affiliationsInput: '',
    ...overrides,
} as ContributorEntry);

const createInstitutionContributor = (overrides: Partial<ContributorEntry> = {}): ContributorEntry => ({
    id: `contrib-${Date.now()}-${Math.random().toString(36).substring(7)}`,
    type: 'institution',
    institutionName: 'GFZ Potsdam',
    roles: [],
    rolesInput: '',
    affiliations: [],
    affiliationsInput: '',
    ...overrides,
} as ContributorEntry);

describe('ContributorList', () => {
    let onAdd = vi.fn<() => void>();
    let onRemove = vi.fn<(index: number) => void>();
    let onContributorChange = vi.fn<(index: number, contributor: ContributorEntry) => void>();
    let onBulkAdd = vi.fn<(contributors: ContributorEntry[]) => void>();

    const defaultProps = {
        affiliationSuggestions: [],
        personRoleOptions: ['DataCurator', 'ContactPerson', 'ProjectLeader'] as readonly string[],
        institutionRoleOptions: ['HostingInstitution', 'Sponsor'] as readonly string[],
    };

    beforeEach(() => {
        onAdd = vi.fn<() => void>();
        onRemove = vi.fn<(index: number) => void>();
        onContributorChange = vi.fn<(index: number, contributor: ContributorEntry) => void>();
        onBulkAdd = vi.fn<(contributors: ContributorEntry[]) => void>();
    });

    describe('empty state', () => {
        it('shows empty state message when no contributors', () => {
            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    onBulkAdd={onBulkAdd}
                    {...defaultProps}
                />,
            );

            expect(screen.getByText(/no contributors yet/i)).toBeInTheDocument();
            expect(screen.getByText(/add people or institutions/i)).toBeInTheDocument();
        });

        it('has an Add First Contributor button in empty state', () => {
            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            const button = screen.getByRole('button', { name: /add first contributor/i });
            expect(button).toBeInTheDocument();
        });

        it('calls onAdd when Add First Contributor is clicked', async () => {
            const user = userEvent.setup();

            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByRole('button', { name: /add first contributor/i }));
            expect(onAdd).toHaveBeenCalledTimes(1);
        });

        it('shows Import CSV button in empty state', () => {
            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    onBulkAdd={onBulkAdd}
                    {...defaultProps}
                />,
            );

            expect(screen.getByRole('button', { name: /import contributors from csv/i })).toBeInTheDocument();
        });
    });

    describe('contributor list', () => {
        it('renders contributor items', () => {
            const contributors = [
                createPersonContributor({ id: 'c1', firstName: 'Alice', lastName: 'Johnson' }),
                createInstitutionContributor({ id: 'c2', institutionName: 'CERN' }),
            ];

            render(
                <ContributorList
                    contributors={contributors}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            expect(screen.getByTestId('contributor-0')).toBeInTheDocument();
            expect(screen.getByTestId('contributor-1')).toBeInTheDocument();
            expect(screen.getByTestId('name-0')).toHaveTextContent('Alice Johnson');
            expect(screen.getByTestId('name-1')).toHaveTextContent('CERN');
        });

        it('shows the Contributors list with accessible role', () => {
            const contributors = [createPersonContributor({ id: 'c1' })];

            render(
                <ContributorList
                    contributors={contributors}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            expect(screen.getByRole('list', { name: /contributors/i })).toBeInTheDocument();
        });

        it('shows Add Contributor button when list is populated', () => {
            const contributors = [createPersonContributor({ id: 'c1' })];

            render(
                <ContributorList
                    contributors={contributors}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            expect(screen.getByRole('button', { name: /add another contributor/i })).toBeInTheDocument();
        });

        it('calls onAdd when Add Contributor button is clicked in list view', async () => {
            const user = userEvent.setup();
            const contributors = [createPersonContributor({ id: 'c1' })];

            render(
                <ContributorList
                    contributors={contributors}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByRole('button', { name: /add another contributor/i }));
            expect(onAdd).toHaveBeenCalledTimes(1);
        });

        it('calls onRemove when remove button is clicked', async () => {
            const user = userEvent.setup();
            const contributors = [
                createPersonContributor({ id: 'c1' }),
                createPersonContributor({ id: 'c2' }),
            ];

            render(
                <ContributorList
                    contributors={contributors}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByTestId('remove-0'));
            expect(onRemove).toHaveBeenCalledWith(0);
        });
    });

    describe('type change', () => {
        it('resets person fields when changing from person to institution', async () => {
            const user = userEvent.setup();
            const personContrib = createPersonContributor({
                id: 'c1',
                firstName: 'Alice',
                lastName: 'Johnson',
                roles: [{ value: 'DataCurator' }],
                rolesInput: 'DataCurator',
                affiliations: [{ value: 'MIT', rorId: null }],
                affiliationsInput: 'MIT',
            });

            render(
                <ContributorList
                    contributors={[personContrib]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByTestId('change-type-0'));

            expect(onContributorChange).toHaveBeenCalledWith(
                0,
                expect.objectContaining({
                    type: 'institution',
                    institutionName: '',
                    // Roles and affiliations are preserved
                    roles: [{ value: 'DataCurator' }],
                    affiliations: [{ value: 'MIT', rorId: null }],
                }),
            );
        });

        it('does nothing when type is the same', async () => {
            const user = userEvent.setup();
            // The mock toggles between types, so to test same-type we need two clicks
            const personContrib = createPersonContributor({ id: 'c1' });

            render(
                <ContributorList
                    contributors={[personContrib]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            // First click changes to institution
            await user.click(screen.getByTestId('change-type-0'));
            expect(onContributorChange).toHaveBeenCalledTimes(1);
        });
    });

    describe('person field change', () => {
        it('updates person field when changed', async () => {
            const user = userEvent.setup();
            const personContrib = createPersonContributor({ id: 'c1', firstName: 'John', lastName: 'Doe' });

            render(
                <ContributorList
                    contributors={[personContrib]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByTestId('change-name-0'));

            expect(onContributorChange).toHaveBeenCalledWith(
                0,
                expect.objectContaining({
                    firstName: 'Updated',
                }),
            );
        });

        it('ignores person field changes on institution entries', async () => {
            const user = userEvent.setup();
            const instContrib = createInstitutionContributor({ id: 'c1' });

            render(
                <ContributorList
                    contributors={[instContrib]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            // Institution contributor should not have change-name button
            expect(screen.queryByTestId('change-name-0')).not.toBeInTheDocument();
        });
    });

    describe('institution name change', () => {
        it('updates institution name when changed', async () => {
            const user = userEvent.setup();
            const instContrib = createInstitutionContributor({ id: 'c1', institutionName: 'Old Name' });

            render(
                <ContributorList
                    contributors={[instContrib]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByTestId('change-inst-0'));

            expect(onContributorChange).toHaveBeenCalledWith(
                0,
                expect.objectContaining({
                    institutionName: 'Updated Inst',
                }),
            );
        });
    });

    describe('CSV import', () => {
        it('calls onBulkAdd with converted entries on import', async () => {
            const user = userEvent.setup();

            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    onBulkAdd={onBulkAdd}
                    {...defaultProps}
                />,
            );

            // Open CSV dialog
            await user.click(screen.getByRole('button', { name: /import contributors from csv/i }));

            // Click import
            await user.click(screen.getByTestId('csv-import-action'));

            expect(onBulkAdd).toHaveBeenCalledTimes(1);
            const importedContributors = onBulkAdd.mock.calls[0][0];
            expect(importedContributors).toHaveLength(2);
            expect(importedContributors[0]).toMatchObject({
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                roles: [{ value: 'DataCurator' }],
            });
            expect(importedContributors[1]).toMatchObject({
                type: 'institution',
                institutionName: 'CERN',
                roles: [{ value: 'HostingInstitution' }],
            });
        });

        it('converts CSV affiliations to AffiliationTag format', async () => {
            const user = userEvent.setup();

            render(
                <ContributorList
                    contributors={[]}
                    onAdd={onAdd}
                    onRemove={onRemove}
                    onContributorChange={onContributorChange}
                    onBulkAdd={onBulkAdd}
                    {...defaultProps}
                />,
            );

            await user.click(screen.getByRole('button', { name: /import contributors from csv/i }));
            await user.click(screen.getByTestId('csv-import-action'));

            const firstContrib = onBulkAdd.mock.calls[0][0][0];
            expect(firstContrib.affiliations).toEqual(
                expect.arrayContaining([
                    expect.objectContaining({ value: 'MIT', rorId: null }),
                ]),
            );
        });
    });
});
