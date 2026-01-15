import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
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
}));

const settingsRoute = vi.hoisted(() => ({ url: '/settings' }));
vi.mock('@/routes', () => ({ settings: () => settingsRoute }));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children }: { children?: React.ReactNode }) => <button>{children}</button>,
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

describe('EditorSettings page', () => {
    it('renders resource and title types and settings fields', () => {
        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset', active: true, elmo_active: false }]}
                titleTypes={[{ id: 1, name: 'Main Title', slug: 'main-title', active: true, elmo_active: false }]}
                licenses={[]}
                languages={[{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }]}
                dateTypes={[{ id: 1, name: 'Accepted', slug: 'accepted', description: 'Test description', active: true, elmo_active: false }]}
                maxTitles={10}
                maxLicenses={5}
            />,
        );
        const grid = screen.getByTestId('bento-grid');
        expect(grid).toBeInTheDocument();
        expect(grid).toHaveClass('md:grid-cols-2');
        expect(grid).not.toHaveClass('lg:grid-cols-3');
        const items = grid.querySelectorAll('[data-slot="bento-grid-item"]');
        expect(items).toHaveLength(6);
        items.forEach((item) => expect(item).toHaveClass('self-start'));
        expect(items[0]).toHaveClass('md:row-span-5');
        expect(within(items[0] as HTMLElement).getByText('Licenses')).toBeInTheDocument();
        expect(within(items[1] as HTMLElement).getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getAllByLabelText('Name')).toHaveLength(2);
        expect(screen.getAllByLabelText('ERNIE active')).toHaveLength(4);
        expect(screen.getAllByLabelText('ELMO active')).toHaveLength(4);
        expect(screen.getByLabelText('Slug')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Titles')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Licenses')).toBeInTheDocument();
        screen.getAllByRole('table').forEach((table) => {
            expect(table.parentElement).toHaveClass('overflow-x-auto');
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
                        elmo_active: false,
                    },
                ],
                maxTitles: 10,
                maxLicenses: 5,
            }),
        );
    });

    it('renders licenses table with correct data', () => {
        useFormMock.mockReturnValueOnce({
            data: {
                resourceTypes: [],
                titleTypes: [],
                licenses: [
                    { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0', active: true, elmo_active: false },
                    { id: 2, identifier: 'CC0', name: 'Public Domain', active: false, elmo_active: true },
                ],
                languages: [],
                dateTypes: [],
                maxTitles: 10,
                maxLicenses: 5,
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
                    { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0', active: true, elmo_active: false },
                    { id: 2, identifier: 'CC0', name: 'Public Domain', active: false, elmo_active: true },
                ]}
                languages={[]}
                dateTypes={[]}
                maxTitles={10}
                maxLicenses={5}
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
                    { id: 1, name: 'Collected', slug: 'collected', description: 'Date when data was collected', active: true, elmo_active: true },
                    { id: 2, name: 'Created', slug: 'created', description: 'Date of creation', active: false, elmo_active: false },
                ],
                maxTitles: 10,
                maxLicenses: 5,
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
                    { id: 1, name: 'Collected', slug: 'collected', description: 'Date when data was collected', active: true, elmo_active: true },
                    { id: 2, name: 'Created', slug: 'created', description: 'Date of creation', active: false, elmo_active: false },
                ]}
                maxTitles={10}
                maxLicenses={5}
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
            />,
        );

        expect(screen.getByDisplayValue('Dataset')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Collection')).toBeInTheDocument();
    });
});
