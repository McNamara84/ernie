import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
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
        const [ernieHeader] = screen.getAllByRole('columnheader', {
            name: 'ERNIE active',
        });
        expect(ernieHeader).toHaveClass('text-center');
        expect(ernieHeader.innerHTML).toContain('ERNIE<br');
        const [elmoHeader] = screen.getAllByRole('columnheader', {
            name: 'ELMO active',
        });
        expect(elmoHeader).toHaveClass('text-center');
        expect(elmoHeader.innerHTML).toContain('ELMO<br');
        const ernieCell = screen.getAllByLabelText('ERNIE active')[0].closest('td')!;
        const elmoCell = screen.getAllByLabelText('ELMO active')[0].closest('td')!;
        expect(ernieCell).toHaveClass('text-center');
        expect(elmoCell).toHaveClass('text-center');
    });

    it('uses a two-column layout with all cards except Licenses in the second column', () => {
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

        const grid = screen.getByTestId('bento-grid');
        expect(grid).toHaveClass('md:grid-cols-2');
        expect(grid).not.toHaveClass('lg:grid-cols-3');

        // Verify all regions exist - they flow naturally into the grid
        // (Licenses spans 5 rows, others stack in right column)
        const resourceTypesRegion = screen.getByRole('region', { name: 'Resource Types' });
        expect(resourceTypesRegion).toBeInTheDocument();

        const titleTypesRegion = screen.getByRole('region', { name: 'Title Types' });
        expect(titleTypesRegion).toBeInTheDocument();

        const languagesRegion = screen.getByRole('region', { name: 'Languages' });
        expect(languagesRegion).toBeInTheDocument();

        const dateTypesRegion = screen.getByRole('region', { name: 'Date Types' });
        expect(dateTypesRegion).toBeInTheDocument();

        const limitsRegion = screen.getByRole('region', { name: 'Limits' });
        expect(limitsRegion).toBeInTheDocument();
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

    it('associates limits section with a heading for accessibility', () => {
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
        const region = screen.getByRole('region', { name: 'Limits' });
        expect(region).toHaveAttribute('aria-labelledby', 'limits-heading');
        const heading = within(region).getByRole('heading', { name: 'Limits' });
        expect(heading).toHaveAttribute('id', 'limits-heading');
        expect(within(region).getByLabelText('Max Titles')).toBeInTheDocument();
        expect(within(region).getByLabelText('Max Licenses')).toBeInTheDocument();
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

