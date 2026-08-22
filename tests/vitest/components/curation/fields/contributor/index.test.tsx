import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import ContributorField from '@/components/curation/fields/contributor';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';

vi.mock('@/components/curation/fields/contributor/contributor-list', () => ({
    default: ({
        contributors,
        onContributorChange,
        onReorder,
        onBulkAdd,
    }: {
        contributors: ContributorEntry[];
        onContributorChange: (index: number, contributor: ContributorEntry) => void;
        onReorder: (contributors: ContributorEntry[]) => void;
        onBulkAdd: (contributors: ContributorEntry[]) => void;
    }) => (
        <div data-testid="contributor-list">
            <button
                data-testid="reorder-contributors"
                onClick={() =>
                    onReorder([
                        contributors[2],
                        contributors[0],
                        contributors[1],
                    ])
                }
            >
                Reorder contributors
            </button>
            <button
                data-testid="change-first-contributor"
                onClick={() =>
                    onContributorChange(0, {
                        ...contributors[0],
                        firstName: contributors[0].type === 'person' ? 'Updated' : undefined,
                    } as ContributorEntry)
                }
            >
                Change first contributor
            </button>
            <button
                data-testid="bulk-add-contributors"
                onClick={() =>
                    onBulkAdd(
                        Array.from({ length: 150 }, (_, index) => ({
                            ...contributors[0],
                            id: `imported-contributor-${index}`,
                        })),
                    )
                }
            >
                Bulk add contributors
            </button>
        </div>
    ),
}));

describe('ContributorField', () => {
    const contributors: ContributorEntry[] = [
        {
            id: 'contributor-1',
            type: 'person',
            orcid: '0000-0001-1111-1111',
            firstName: 'Alice',
            lastName: 'Johnson',
            email: 'alice@example.org',
            website: 'https://example.org/alice',
            roles: [{ value: 'DataCurator' }],
            rolesInput: 'DataCurator',
            affiliations: [{ value: 'University A', rorId: null }],
            affiliationsInput: 'University A',
            orcidVerified: true,
        },
        {
            id: 'contributor-2',
            type: 'institution',
            institutionName: 'Institute B',
            roles: [{ value: 'HostingInstitution' }],
            rolesInput: 'HostingInstitution',
            affiliations: [{ value: 'Institute B', rorId: 'https://ror.org/02abcde12' }],
            affiliationsInput: 'Institute B',
        },
        {
            id: 'contributor-3',
            type: 'person',
            orcid: '0000-0002-2222-2222',
            firstName: 'Grace',
            lastName: 'Hopper',
            email: 'grace@example.org',
            website: 'https://example.org/grace',
            roles: [{ value: 'ProjectLeader' }],
            rolesInput: 'ProjectLeader',
            affiliations: [{ value: 'Lab C', rorId: null }],
            affiliationsInput: 'Lab C',
            orcidVerified: false,
        },
    ];

    const roleProps = {
        personRoleOptions: ['DataCurator', 'ProjectLeader'] as readonly string[],
        institutionRoleOptions: ['HostingInstitution'] as readonly string[],
    };

    it('replaces the full contributor array when the list reports a reorder', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn<(contributors: ContributorEntry[]) => void>();

        render(<ContributorField contributors={contributors} onChange={onChange} affiliationSuggestions={[]} {...roleProps} />);

        await user.click(screen.getByTestId('reorder-contributors'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ id: 'contributor-3', firstName: 'Grace', lastName: 'Hopper' }),
            expect.objectContaining({ id: 'contributor-1', firstName: 'Alice', lastName: 'Johnson' }),
            expect.objectContaining({ id: 'contributor-2', institutionName: 'Institute B' }),
        ]);
    });

    it('still updates a single contributor by index for non-reorder changes', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn<(contributors: ContributorEntry[]) => void>();

        render(<ContributorField contributors={contributors} onChange={onChange} affiliationSuggestions={[]} {...roleProps} />);

        await user.click(screen.getByTestId('change-first-contributor'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ id: 'contributor-1', firstName: 'Updated', lastName: 'Johnson' }),
            expect.objectContaining({ id: 'contributor-2', institutionName: 'Institute B' }),
            expect.objectContaining({ id: 'contributor-3', firstName: 'Grace', lastName: 'Hopper' }),
        ]);
    });

    it('does not truncate a bulk import beyond the former contributor limit', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn<(contributors: ContributorEntry[]) => void>();

        render(<ContributorField contributors={contributors} onChange={onChange} affiliationSuggestions={[]} {...roleProps} />);
        await user.click(screen.getByTestId('bulk-add-contributors'));

        expect(onChange).toHaveBeenCalledOnce();
        expect(onChange.mock.calls[0][0]).toHaveLength(153);
        expect(onChange.mock.calls[0][0][152]).toMatchObject({ id: 'imported-contributor-149' });
    });
});
