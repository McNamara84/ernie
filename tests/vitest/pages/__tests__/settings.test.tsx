import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
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
        const resourceTypes = [{ id: 1, name: 'Dataset', active: true, elmo_active: false }];
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
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false }]}
                titleTypes={[{ id: 1, name: 'Article', slug: 'article', active: true, elmo_active: false }]}
                licenses={[]}
                languages={[{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }]}
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
        expect(screen.getAllByRole('button', { expanded: false })).toHaveLength(15);
    });

    it('updates ERNIE active when toggled', () => {
        const resourceTypes = [{ id: 1, name: 'Dataset', active: false, elmo_active: false }];
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
        expect(setData).toHaveBeenCalledWith('resourceTypes', [{ id: 1, name: 'Dataset', active: true, elmo_active: false }]);
    });

    it('updates ELMO active when toggled', () => {
        const resourceTypes = [{ id: 1, name: 'Dataset', active: true, elmo_active: false }];
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
        expect(setData).toHaveBeenCalledWith('resourceTypes', [{ id: 1, name: 'Dataset', active: true, elmo_active: true }]);
    });
});

describe('License settings', () => {
    it('updates license ERNIE active when toggled', () => {
        const licenses = [{ id: 1, identifier: 'MIT', name: 'MIT License', active: false, elmo_active: false, excluded_resource_type_ids: [] }];
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
            { id: 1, identifier: 'MIT', name: 'MIT License', active: true, elmo_active: false, excluded_resource_type_ids: [] },
        ]);
    });
});

describe('Language settings', () => {
    it('updates language ERNIE active when toggled', () => {
        const languages = [{ id: 1, code: 'en', name: 'English', active: false, elmo_active: false }];
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
        expect(setData).toHaveBeenCalledWith('languages', [{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }]);
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
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: true },
        ]);
    });
});
