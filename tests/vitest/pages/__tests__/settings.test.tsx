import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import EditorSettings from '@/pages/settings/index';

const setData = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    useForm: (initial: unknown) => ({
        data: initial,
        setData,
        post: vi.fn(),
        processing: false,
        isDirty: false,
        recentlySuccessful: false,
    }),
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin User',
                    role: 'admin',
                },
            },
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: React.ComponentProps<'button'>) => <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: React.ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: React.ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: ({
        onCheckedChange,
        checked,
        indeterminate,
        ...props
    }: { onCheckedChange?: (checked: boolean) => void; checked?: boolean; indeterminate?: boolean } & React.ComponentProps<'input'>) => (
        <input
            type="checkbox"
            checked={checked ?? false}
            data-indeterminate={indeterminate ? 'true' : undefined}
            {...props}
            onChange={(e) => onCheckedChange?.(e.target.checked)}
        />
    ),
}));

vi.mock('@/components/settings/thesaurus-card', () => ({
    ThesaurusCard: () => <div data-testid="thesaurus-card-mock">Thesaurus Card Mock</div>,
}));

// Default thesauri mock data for tests
const defaultThesauri = [
    {
        type: 'science_keywords',
        displayName: 'Science Keywords',
        isActive: true,
        isElmoActive: false,
        exists: true,
        conceptCount: 100,
        lastUpdated: null,
    },
    { type: 'platforms', displayName: 'Platforms', isActive: true, isElmoActive: false, exists: true, conceptCount: 50, lastUpdated: null },
    { type: 'instruments', displayName: 'Instruments', isActive: true, isElmoActive: false, exists: true, conceptCount: 200, lastUpdated: null },
];

beforeEach(() => {
    setData.mockClear();
});

describe('EditorSettings page', () => {
    it('renders centered active columns with line breaks in headers', () => {
        const resourceTypes = [{ id: 1, name: 'Dataset', active: true, elmo_active: false, elmo_msl_active: false }];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Resource Types/ }));
        // Find headers by their text content (handles different accessible name interpretations)
        const allHeaders = screen.getAllByRole('columnheader');
        const ernieHeader = allHeaders.find((h) => h.textContent?.includes('ERNIE') && h.textContent?.includes('active'));
        const elmoHeader = allHeaders.find((h) => h.textContent?.includes('ELMO') && h.textContent?.includes('active'));

        expect(ernieHeader).toBeDefined();
        expect(ernieHeader).toHaveClass('text-center');
        expect(ernieHeader?.innerHTML).toContain('ERNIE');

        expect(elmoHeader).toBeDefined();
        expect(elmoHeader).toHaveClass('text-center');
        expect(elmoHeader?.innerHTML).toContain('ELMO');

        const ernieCell = screen.getAllByLabelText('ERNIE active')[0].closest('td')!;
        const elmoCell = screen.getAllByLabelText('ELMO active')[0].closest('td')!;
        expect(ernieCell).toHaveClass('text-center');
        expect(elmoCell).toHaveClass('text-center');
    });

    it('uses one full-width accordion with all sections collapsed initially', () => {
        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false, elmo_msl_active: false }]}
                titleTypes={[{ id: 1, name: 'Article', slug: 'article', active: true, elmo_active: false, elmo_msl_active: false }]}
                licenses={[]}
                languages={[{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false, elmo_msl_active: false }]}
                dateTypes={[{ id: 1, name: 'Accepted', slug: 'accepted', description: 'Test', active: true }]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );

        const accordion = screen.getByTestId('settings-accordion');
        expect(accordion).toHaveClass('flex', 'flex-col');
        expect(screen.queryByTestId('settings-grid')).not.toBeInTheDocument();

        // Verify all headings exist for each card
        expect(screen.getByText('Licenses')).toBeInTheDocument();
        expect(screen.getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getByText('Title Types')).toBeInTheDocument();
        expect(screen.getByText('Languages')).toBeInTheDocument();
        expect(screen.getByText('Date Types')).toBeInTheDocument();
        expect(screen.queryByText('Limits')).not.toBeInTheDocument();
        expect(screen.getByText('Thesauri')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { expanded: false })).toHaveLength(16);
    });

    it('updates ERNIE active when toggled', () => {
        const resourceTypes = [{ id: 1, name: 'Dataset', active: false, elmo_active: false, elmo_msl_active: false }];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Resource Types/ }));
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('resourceTypes', [{ id: 1, name: 'Dataset', active: true, elmo_active: false, elmo_msl_active: false }]);
    });

    it('updates ELMO active when toggled', () => {
        const resourceTypes = [{ id: 1, name: 'Dataset', active: true, elmo_active: false, elmo_msl_active: false }];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Resource Types/ }));
        fireEvent.click(screen.getByLabelText('ELMO active'));
        expect(setData).toHaveBeenCalledWith('resourceTypes', [{ id: 1, name: 'Dataset', active: true, elmo_active: true, elmo_msl_active: false }]);
    });
});

describe('License settings', () => {
    it('updates license ERNIE active when toggled', () => {
        const licenses = [
            {
                id: 1,
                identifier: 'MIT',
                name: 'MIT License',
                active: false,
                elmo_active: false,
                elmo_msl_active: false,
                excluded_resource_type_ids: [],
                elmo_excluded_resource_type_ids: [],
                elmo_msl_excluded_resource_type_ids: [],
            },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={licenses}
                languages={[]}
                dateTypes={[]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Licenses/ }));
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('licenses', [
            {
                id: 1,
                identifier: 'MIT',
                name: 'MIT License',
                active: true,
                elmo_active: false,
                elmo_msl_active: false,
                excluded_resource_type_ids: [],
                elmo_excluded_resource_type_ids: [],
                elmo_msl_excluded_resource_type_ids: [],
            },
        ]);
    });
});

describe('Language settings', () => {
    it('updates language ERNIE active when toggled', () => {
        const languages = [{ id: 1, code: 'en', name: 'English', active: false, elmo_active: false, elmo_msl_active: false }];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={languages}
                dateTypes={[]}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Languages/ }));
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('languages', [
            { id: 1, code: 'en', name: 'English', active: true, elmo_active: false, elmo_msl_active: false },
        ]);
    });
});

describe('Date Type settings', () => {
    it('updates date type ERNIE active when toggled', () => {
        const dateTypes = [
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={dateTypes}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
                relationTypes={[]}
                identifierTypes={[]}
                datacenters={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Date Types/ }));
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('dateTypes', [
            {
                id: 1,
                name: 'Accepted',
                slug: 'accepted',
                description: 'The date that the publisher accepted the resource.',
                active: true,
                elmo_active: false,
                elmo_msl_active: false,
            },
        ]);
    });
});

const independentSettingsProps: React.ComponentProps<typeof EditorSettings> = {
    resourceTypes: [],
    titleTypes: [],
    licenses: [],
    languages: [],
    dateTypes: [],
    descriptionTypes: [],
    contributorPersonRoles: [],
    contributorInstitutionRoles: [],
    contributorBothRoles: [],
    relationTypes: [],
    identifierTypes: [],
    thesauri: [],
    pidSettings: [],
    landingPageDomains: [],
    datacenters: [],
};

const editorSections = [
    ['resourceTypes', 'Resource Types'],
    ['titleTypes', 'Title Types'],
    ['licenses', 'Licenses'],
    ['languages', 'Languages'],
    ['dateTypes', 'Date Types'],
    ['descriptionTypes', 'Description Types'],
    ['contributorPersonRoles', 'Contributor Roles (Persons)'],
    ['contributorInstitutionRoles', 'Contributor Roles (Institutions)'],
    ['contributorBothRoles', 'Contributor Roles (Both)'],
    ['relationTypes', 'Relation Types'],
    ['identifierTypes', 'Identifier Types'],
] as const;

function isolationRows() {
    return [1, 2].map((id) => ({
        id,
        name: `Option ${id}`,
        slug: `option-${id}`,
        code: `x${id}`,
        identifier: `LICENSE-${id}`,
        description: 'Description',
        patterns: [],
        category: 'both' as const,
        active: true,
        elmo_active: false,
        elmo_msl_active: id === 1,
        excluded_resource_type_ids: [],
        elmo_excluded_resource_type_ids: [],
        elmo_msl_excluded_resource_type_ids: [],
    }));
}

describe('independent ELMO-MSL settings controls', () => {
    it.each(editorSections)('changes a single %s MSL flag without changing the other editors', (key, title) => {
        render(<EditorSettings {...independentSettingsProps} {...{ [key]: isolationRows() }} />);
        fireEvent.click(screen.getByRole('button', { name: (name) => name.startsWith(title) }));
        fireEvent.click(screen.getAllByLabelText('ELMO-MSL active')[1]);
        expect(setData).toHaveBeenCalledTimes(1);
        expect(setData).toHaveBeenCalledWith(key, [
            expect.objectContaining({ id: 1, active: true, elmo_active: false, elmo_msl_active: true }),
            expect.objectContaining({ id: 2, active: true, elmo_active: false, elmo_msl_active: true }),
        ]);
    });

    it.each(editorSections)('selects all %s MSL flags from a mixed state in one update', (key, title) => {
        render(<EditorSettings {...independentSettingsProps} {...{ [key]: isolationRows() }} />);
        fireEvent.click(screen.getByRole('button', { name: (name) => name.startsWith(title) }));
        const selectAll = screen.getByLabelText(`Select all ELMO-MSL active for ${title}`);
        expect(selectAll).toHaveAttribute('data-indeterminate', 'true');
        fireEvent.click(selectAll);
        expect(setData).toHaveBeenCalledTimes(1);
        expect(setData).toHaveBeenCalledWith(key, [
            expect.objectContaining({ id: 1, active: true, elmo_active: false, elmo_msl_active: true }),
            expect.objectContaining({ id: 2, active: true, elmo_active: false, elmo_msl_active: true }),
        ]);
    });

    it('selects all PID registries with one update and preserves ERNIE and ELMO', () => {
        render(
            <EditorSettings
                {...independentSettingsProps}
                pidSettings={['ror', 'raid'].map((type) => ({
                    type,
                    displayName: type,
                    isActive: true,
                    isElmoActive: false,
                    isElmoMslActive: false,
                    exists: false,
                    itemCount: 0,
                    lastUpdated: null,
                }))}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Persistent Identifiers/ }));
        fireEvent.click(screen.getByLabelText('Select all ELMO-MSL active for PID Settings'));
        expect(setData).toHaveBeenCalledTimes(1);
        expect(setData).toHaveBeenCalledWith('pidSettings', [
            expect.objectContaining({ type: 'ror', isActive: true, isElmoActive: false, isElmoMslActive: true }),
            expect.objectContaining({ type: 'raid', isActive: true, isElmoActive: false, isElmoMslActive: true }),
        ]);
    });

    it('changes only the MSL resource-type exclusions for a license', () => {
        render(
            <EditorSettings
                {...independentSettingsProps}
                resourceTypes={isolationRows()}
                licenses={[{ ...isolationRows()[0], excluded_resource_type_ids: [1], elmo_excluded_resource_type_ids: [2] }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Licenses/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Configure resource types for Option 1 (ELMO-MSL)' }));
        fireEvent.click(screen.getByLabelText('Option 2'));
        expect(setData).toHaveBeenCalledWith('licenses', [
            expect.objectContaining({
                excluded_resource_type_ids: [1],
                elmo_excluded_resource_type_ids: [2],
                elmo_msl_excluded_resource_type_ids: [2],
            }),
        ]);
    });
});
