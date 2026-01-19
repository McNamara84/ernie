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
    Button: ({ children, ...props }: React.ComponentProps<'button'>) => (
        <button {...props}>{children}</button>
    ),
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: React.ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: React.ComponentProps<'label'>) => (
        <label {...props}>{children}</label>
    ),
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: ({ onCheckedChange, ...props }: { onCheckedChange?: (checked: boolean) => void } & React.ComponentProps<'input'>) => (
        <input
            type="checkbox"
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
    { type: 'science_keywords', displayName: 'Science Keywords', isActive: true, isElmoActive: false, exists: true, conceptCount: 100, lastUpdated: null },
    { type: 'platforms', displayName: 'Platforms', isActive: true, isElmoActive: false, exists: true, conceptCount: 50, lastUpdated: null },
    { type: 'instruments', displayName: 'Instruments', isActive: true, isElmoActive: false, exists: true, conceptCount: 200, lastUpdated: null },
];

beforeEach(() => {
    setData.mockClear();
});

describe('EditorSettings page', () => {
    it('renders centered active columns with line breaks in headers', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
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

    it('uses a two-column layout with Licenses on the left and other cards on the right', () => {
        render(
            <EditorSettings
                resourceTypes={[
                    { id: 1, name: 'Dataset', active: true, elmo_active: false },
                ]}
                titleTypes={[
                    { id: 1, name: 'Article', slug: 'article', active: true, elmo_active: false },
                ]}
                licenses={[]}
                languages={[
                    { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
                ]}
                dateTypes={[
                    { id: 1, name: 'Accepted', slug: 'accepted', description: 'Test', active: true, elmo_active: false },
                ]}
                maxTitles={5}
                maxLicenses={10}
                thesauri={defaultThesauri}
            />,
        );

        const grid = screen.getByTestId('settings-grid');
        expect(grid).toHaveClass('md:grid-cols-2');

        // Verify all headings exist for each card
        expect(screen.getByText('Licenses')).toBeInTheDocument();
        expect(screen.getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getByText('Title Types')).toBeInTheDocument();
        expect(screen.getByText('Languages')).toBeInTheDocument();
        expect(screen.getByText('Date Types')).toBeInTheDocument();
        expect(screen.getByText('Limits')).toBeInTheDocument();
        expect(screen.getByText('Thesauri')).toBeInTheDocument();
    });

    it('updates ERNIE active when toggled', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: false, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ]);
    });

    it('updates ELMO active when toggled', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ELMO active'));
        expect(setData).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: true, elmo_active: true },
        ]);
    });

    it('renders limit fields without extra top margin', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        const grid = screen.getByLabelText('Max Titles').closest('div')!.parentElement;
        expect(grid).not.toHaveClass('mt-8');
    });

    it('renders limits section with heading and inputs', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={resourceTypes}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        // Verify Limits card exists with heading
        expect(screen.getByRole('heading', { name: 'Limits' })).toBeInTheDocument();
        expect(screen.getByLabelText('Max Titles')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Licenses')).toBeInTheDocument();
    });
});

describe('License settings', () => {
    it('updates license ERNIE active when toggled', () => {
        const licenses = [
            { id: 1, identifier: 'MIT', name: 'MIT License', active: false, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={licenses}
                languages={[]}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('licenses', [
            { id: 1, identifier: 'MIT', name: 'MIT License', active: true, elmo_active: false },
        ]);
    });
});

describe('Language settings', () => {
    it('updates language ERNIE active when toggled', () => {
        const languages = [
            { id: 1, code: 'en', name: 'English', active: false, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={languages}
                dateTypes={[]}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('languages', [
            { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
        ]);
    });
});

describe('Date Type settings', () => {
    it('updates date type ERNIE active when toggled', () => {
        const dateTypes = [
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: false, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={dateTypes}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('dateTypes', [
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: true, elmo_active: false },
        ]);
    });

    it('updates date type ELMO active when toggled', () => {
        const dateTypes = [
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: false, elmo_active: false },
        ];
        render(
            <EditorSettings
                resourceTypes={[]}
                titleTypes={[]}
                licenses={[]}
                languages={[]}
                dateTypes={dateTypes}
                maxTitles={1}
                maxLicenses={1}
                thesauri={defaultThesauri}
            />,
        );
        fireEvent.click(screen.getByLabelText('ELMO active'));
        expect(setData).toHaveBeenCalledWith('dateTypes', [
            { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date that the publisher accepted the resource.', active: false, elmo_active: true },
        ]);
    });
});

