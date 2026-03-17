import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import EditorSettings from '@/pages/settings/index';

const useFormMock = vi.fn((initial) => ({
    data: initial,
    setData: vi.fn(),
    post: vi.fn(),
    processing: false,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    useForm: (initial: unknown) => useFormMock(initial),
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

const settingsRoute = vi.hoisted(() => ({ url: '/settings' }));
vi.mock('@/routes', () => ({ settings: () => settingsRoute }));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, disabled, ...rest }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
        <button disabled={disabled} {...rest}>
            {children}
        </button>
    ),
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: React.InputHTMLAttributes<HTMLInputElement>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, htmlFor, className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) => (
        <label htmlFor={htmlFor} className={className} {...props}>
            {children}
        </label>
    ),
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: ({
        onCheckedChange,
        checked,
        indeterminate,
        ...props
    }: {
        onCheckedChange?: (checked: boolean) => void;
        checked?: boolean;
        indeterminate?: boolean;
    } & React.ComponentProps<'input'>) => (
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

// Default thesauri mock data for tests (full props)
const defaultThesauri = [
    { type: 'science_keywords', displayName: 'Science Keywords', isActive: true, isElmoActive: false, exists: true, conceptCount: 100, lastUpdated: null },
    { type: 'platforms', displayName: 'Platforms', isActive: true, isElmoActive: false, exists: true, conceptCount: 50, lastUpdated: null },
    { type: 'instruments', displayName: 'Instruments', isActive: true, isElmoActive: false, exists: true, conceptCount: 200, lastUpdated: null },
];

// Form data thesauri (only what useForm needs)
const defaultThesauriFormData = [
    { type: 'science_keywords', isActive: true, isElmoActive: false },
    { type: 'platforms', isActive: true, isElmoActive: false },
    { type: 'instruments', isActive: true, isElmoActive: false },
];

describe('EditorSettings page', () => {
    it('renders resource and title types and settings fields', () => {
        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false }]}
                titleTypes={[{ id: 1, name: 'Main Title', slug: 'main-title', active: true, elmo_active: false }]}
                licenses={[]}
                languages={[{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }]}
                dateTypes={[{ id: 1, name: 'Accepted', slug: 'accepted', description: 'Test description', active: true }]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );
        const grid = screen.getByTestId('settings-grid');
        expect(grid).toBeInTheDocument();
        expect(grid).toHaveClass('md:grid-cols-2');

        // Verify cards are rendered (7 cards total: Licenses, Resource Types, Title Types, Languages, Date Types, Limits, Thesauri)
        expect(screen.getByText('Licenses')).toBeInTheDocument();
        expect(screen.getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getByText('Title Types')).toBeInTheDocument();
        expect(screen.getByText('Languages')).toBeInTheDocument();
        expect(screen.getByText('Date Types')).toBeInTheDocument();
        expect(screen.getByText('Limits')).toBeInTheDocument();
        expect(screen.getByText('Thesauri')).toBeInTheDocument();

        expect(screen.getAllByLabelText('Name')).toHaveLength(2);
        expect(screen.getAllByLabelText('ERNIE active')).toHaveLength(4);
        expect(screen.getAllByLabelText('ELMO active')).toHaveLength(3);
        expect(screen.getByLabelText('Slug')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Titles')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Licenses')).toBeInTheDocument();
        screen.getAllByRole('table').forEach((table) => {
            expect(table.parentElement).toHaveClass('overflow-auto');
        });
        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        expect(saveButtons).toHaveLength(2);
        expect(saveButtons[0].nextElementSibling).toBe(grid);
        expect(grid.nextElementSibling).toBe(saveButtons[1]);
        expect(within(grid).queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(useFormMock).toHaveBeenCalledWith(
            expect.objectContaining({
                resourceTypes: [{ id: 1, name: 'Dataset', active: true, elmo_active: false }],
                titleTypes: [
                    {
                        id: 1,
                        name: 'Main Title',
                        slug: 'main-title',
                        active: true,
                        elmo_active: false,
                    },
                ],
                licenses: [],
                languages: [
                    {
                        id: 1,
                        code: 'en',
                        name: 'English',
                        active: true,
                        elmo_active: false,
                    },
                ],
                dateTypes: [
                    {
                        id: 1,
                        name: 'Accepted',
                        slug: 'accepted',
                        description: 'Test description',
                        active: true,
                    },
                ],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
            }),
        );
    });

    it('renders licenses table with correct data', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [
                    { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0', active: true, elmo_active: false, excluded_resource_type_ids: [] },
                    { id: 2, identifier: 'CC0', name: 'Public Domain', active: false, elmo_active: true, excluded_resource_type_ids: [] },
                ],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[
                    { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0', active: true, elmo_active: false, excluded_resource_type_ids: [] },
                    { id: 2, identifier: 'CC0', name: 'Public Domain', active: false, elmo_active: true, excluded_resource_type_ids: [] },
                ]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        expect(screen.getByText('CC-BY-4.0')).toBeInTheDocument();
        expect(screen.getByText('Creative Commons Attribution 4.0')).toBeInTheDocument();
        expect(screen.getByText('CC0')).toBeInTheDocument();
        expect(screen.getByText('Public Domain')).toBeInTheDocument();
    });

    it('renders date types table with name and slug', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [
                    { id: 1, name: 'Collected', slug: 'collected', description: 'Date when data was collected', active: true },
                    { id: 2, name: 'Created', slug: 'created', description: 'Date of creation', active: false },
                ],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[
                    { id: 1, name: 'Collected', slug: 'collected', description: 'Date when data was collected', active: true },
                    { id: 2, name: 'Created', slug: 'created', description: 'Date of creation', active: false },
                ]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        // Date Types table shows name and slug columns
        expect(screen.getByText('Collected')).toBeInTheDocument();
        expect(screen.getByText('collected')).toBeInTheDocument();
        expect(screen.getByText('Created')).toBeInTheDocument();
        expect(screen.getByText('created')).toBeInTheDocument();
        // Note: description is in data but not rendered in the table
    });

    it('renders languages table with code and name', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [
                    { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
                    { id: 2, code: 'de', name: 'German', active: true, elmo_active: true },
                ],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[
                    { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
                    { id: 2, code: 'de', name: 'German', active: true, elmo_active: true },
                ]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        expect(screen.getByText('en')).toBeInTheDocument();
        expect(screen.getByText('English')).toBeInTheDocument();
        expect(screen.getByText('de')).toBeInTheDocument();
        expect(screen.getByText('German')).toBeInTheDocument();
    });

    it('displays multiple resource types', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: true },
                ],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: true },
                ]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        expect(screen.getByDisplayValue('Dataset')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Collection')).toBeInTheDocument();
    });

    it('calls setData when resource type name is changed', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [{ id: 1, name: 'Dataset', active: true, elmo_active: false }],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false }]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        const input = screen.getByDisplayValue('Dataset');
        await userEvent.clear(input);
        await userEvent.type(input, 'New Type');

        expect(setDataMock).toHaveBeenCalled();
    });

    it('calls post when form is submitted', async () => {
        const postMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: postMock,
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        await userEvent.click(saveButtons[0]);

        expect(postMock).toHaveBeenCalledWith('/settings');
    });

    it('disables save buttons when processing', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: true,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        saveButtons.forEach((button) => {
            expect(button).toBeDisabled();
        });
    });

    it('renders title types table with name and slug', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [
                    { id: 1, name: 'Main', slug: 'main', active: true, elmo_active: false },
                    { id: 2, name: 'Alternative', slug: 'alternative', active: true, elmo_active: true },
                ],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[
                    { id: 1, name: 'Main', slug: 'main', active: true, elmo_active: false },
                    { id: 2, name: 'Alternative', slug: 'alternative', active: true, elmo_active: true },
                ]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        expect(screen.getByDisplayValue('Main')).toBeInTheDocument();
        expect(screen.getByDisplayValue('main')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Alternative')).toBeInTheDocument();
        expect(screen.getByDisplayValue('alternative')).toBeInTheDocument();
    });

    it('updates maxTitles when input changes', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        const maxTitlesInput = screen.getByLabelText('Max Titles');
        await userEvent.clear(maxTitlesInput);
        await userEvent.type(maxTitlesInput, '15');

        expect(setDataMock).toHaveBeenCalledWith('maxTitles', expect.any(Number));
    });
});

describe('Select All / Deselect All header checkboxes', () => {
    it('renders select-all header checkboxes for all table cards', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [{ id: 1, name: 'Dataset', active: true, elmo_active: false }],
                titleTypes: [{ id: 1, name: 'Main', slug: 'main', active: true, elmo_active: false }],
                licenses: [{ id: 1, identifier: 'MIT', name: 'MIT License', active: true, elmo_active: false, excluded_resource_type_ids: [] }],
                languages: [{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }],
                dateTypes: [{ id: 1, name: 'Created', slug: 'created', description: null, active: true }],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [{ id: 1, name: 'ContactPerson', slug: 'contact-person', category: 'person', active: true, elmo_active: false }],
                contributorInstitutionRoles: [{ id: 2, name: 'Distributor', slug: 'distributor', category: 'institution', active: true, elmo_active: false }],
                contributorBothRoles: [{ id: 3, name: 'Other', slug: 'other', category: 'both', active: true, elmo_active: false }],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false }]}
                titleTypes={[{ id: 1, name: 'Main', slug: 'main', active: true, elmo_active: false }]}
                licenses={[{ id: 1, identifier: 'MIT', name: 'MIT License', active: true, elmo_active: false, excluded_resource_type_ids: [] }]}
                languages={[{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }]}
                dateTypes={[{ id: 1, name: 'Created', slug: 'created', description: null, active: true }]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[{ id: 1, name: 'ContactPerson', slug: 'contact-person', category: 'person', active: true, elmo_active: false }]}
                contributorInstitutionRoles={[{ id: 2, name: 'Distributor', slug: 'distributor', category: 'institution', active: true, elmo_active: false }]}
                contributorBothRoles={[{ id: 3, name: 'Other', slug: 'other', category: 'both', active: true, elmo_active: false }]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        // 7 cards × 2 columns + 1 card (Date Types) × 1 column = 15 select-all checkboxes
        expect(screen.getByLabelText('Select all ERNIE active for Resource Types')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Resource Types')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Title Types')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Title Types')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Licenses')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Licenses')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Languages')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Languages')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Date Types')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Contributor Roles (Persons)')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Contributor Roles (Persons)')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Contributor Roles (Institutions)')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Contributor Roles (Institutions)')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ERNIE active for Contributor Roles (Both)')).toBeInTheDocument();
        expect(screen.getByLabelText('Select all ELMO active for Contributor Roles (Both)')).toBeInTheDocument();
    });

    it('selects all resource types ERNIE active when header checkbox is clicked', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [
                    { id: 1, name: 'Dataset', active: false, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: false, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Resource Types'));
        expect(setDataMock).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
            { id: 2, name: 'Collection', active: true, elmo_active: false },
        ]);
    });

    it('deselects all resource types ERNIE active when header checkbox is unchecked', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: true, elmo_active: false },
                ],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: true, elmo_active: false },
                ]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Resource Types'));
        expect(setDataMock).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: false, elmo_active: false },
            { id: 2, name: 'Collection', active: false, elmo_active: false },
        ]);
    });

    it('shows indeterminate state when resource types have mixed ERNIE active', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        const headerCheckbox = screen.getByLabelText('Select all ERNIE active for Resource Types');
        expect(headerCheckbox).toHaveAttribute('data-indeterminate', 'true');
    });

    it('selects all ELMO active for Resource Types', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [
                    { id: 1, name: 'Dataset', active: false, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: false, elmo_active: false },
                    { id: 2, name: 'Collection', active: false, elmo_active: false },
                ]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ELMO active for Resource Types'));
        expect(setDataMock).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: false, elmo_active: true },
            { id: 2, name: 'Collection', active: false, elmo_active: true },
        ]);
    });

    it('selects all ERNIE active for Licenses', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [
                    { id: 1, identifier: 'MIT', name: 'MIT', active: false, elmo_active: false, excluded_resource_type_ids: [] },
                    { id: 2, identifier: 'CC0', name: 'CC0', active: false, elmo_active: false, excluded_resource_type_ids: [] },
                ],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[
                    { id: 1, identifier: 'MIT', name: 'MIT', active: false, elmo_active: false, excluded_resource_type_ids: [] },
                    { id: 2, identifier: 'CC0', name: 'CC0', active: false, elmo_active: false, excluded_resource_type_ids: [] },
                ]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Licenses'));
        expect(setDataMock).toHaveBeenCalledWith('licenses', [
            { id: 1, identifier: 'MIT', name: 'MIT', active: true, elmo_active: false, excluded_resource_type_ids: [] },
            { id: 2, identifier: 'CC0', name: 'CC0', active: true, elmo_active: false, excluded_resource_type_ids: [] },
        ]);
    });

    it('selects all ERNIE active for Languages', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [
                    { id: 1, code: 'en', name: 'English', active: false, elmo_active: false },
                    { id: 2, code: 'de', name: 'German', active: false, elmo_active: false },
                ],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[
                    { id: 1, code: 'en', name: 'English', active: false, elmo_active: false },
                    { id: 2, code: 'de', name: 'German', active: false, elmo_active: false },
                ]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Languages'));
        expect(setDataMock).toHaveBeenCalledWith('languages', [
            { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
            { id: 2, code: 'de', name: 'German', active: true, elmo_active: false },
        ]);
    });

    it('selects all ERNIE active for Title Types', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [
                    { id: 1, name: 'Main', slug: 'main', active: false, elmo_active: false },
                    { id: 2, name: 'Alternative', slug: 'alt', active: false, elmo_active: false },
                ],
                licenses: [],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[
                    { id: 1, name: 'Main', slug: 'main', active: false, elmo_active: false },
                    { id: 2, name: 'Alternative', slug: 'alt', active: false, elmo_active: false },
                ]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Title Types'));
        expect(setDataMock).toHaveBeenCalledWith('titleTypes', [
            { id: 1, name: 'Main', slug: 'main', active: true, elmo_active: false },
            { id: 2, name: 'Alternative', slug: 'alt', active: true, elmo_active: false },
        ]);
    });

    it('selects all ERNIE active for Date Types', async () => {
        const setDataMock = vi.fn();
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [],
                languages: [],
                dateTypes: [
                    { id: 1, name: 'Created', slug: 'created', description: null, active: false },
                    { id: 2, name: 'Accepted', slug: 'accepted', description: null, active: false },
                ],
                maxTitles: 10,
                maxLicenses: 5,
                thesauri: defaultThesauriFormData,
                contributorPersonRoles: [],
                contributorInstitutionRoles: [],
                contributorBothRoles: [],
                descriptionTypes: [],

                relationTypes: [],

                identifierTypes: [],
            },
            setData: setDataMock,
            post: vi.fn(),
            processing: false,
        });

        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[
                    { id: 1, name: 'Created', slug: 'created', description: null, active: false },
                    { id: 2, name: 'Accepted', slug: 'accepted', description: null, active: false },
                ]}
                maxTitles={10}
                maxLicenses={5}
                thesauri={defaultThesauri}
                pidSettings={[]}
                landingPageDomains={[]}
                contributorPersonRoles={[]}
                contributorInstitutionRoles={[]}
                contributorBothRoles={[]}
                descriptionTypes={[]}
            relationTypes={[]}
            identifierTypes={[]}
            />,
        );

        await userEvent.click(screen.getByLabelText('Select all ERNIE active for Date Types'));
        expect(setDataMock).toHaveBeenCalledWith('dateTypes', [
            { id: 1, name: 'Created', slug: 'created', description: null, active: true },
            { id: 2, name: 'Accepted', slug: 'accepted', description: null, active: true },
        ]);
    });
});
