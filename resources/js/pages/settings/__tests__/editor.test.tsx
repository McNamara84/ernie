import '@testing-library/jest-dom/vitest';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import EditorSettings from '../index';

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
                maxTitles={10}
                maxLicenses={5}
            />,
        );
        const grid = screen.getByTestId('bento-grid');
        expect(grid).toBeInTheDocument();
        expect(grid).toHaveClass('md:grid-cols-2', 'lg:grid-cols-3');
        const items = grid.querySelectorAll('[data-slot="bento-grid-item"]');
        expect(items).toHaveLength(5);
        items.forEach((item) => expect(item).toHaveClass('self-start'));
        expect(items[0]).toHaveClass('md:row-span-4', 'lg:row-span-2');
        expect(within(items[0]).getByText('Licenses')).toBeInTheDocument();
        expect(within(items[1]).getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getAllByLabelText('Name')).toHaveLength(2);
        expect(screen.getAllByLabelText('ERNIE active')).toHaveLength(3);
        expect(screen.getAllByLabelText('ELMO active')).toHaveLength(3);
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
                maxTitles: 10,
                maxLicenses: 5,
            }),
        );
    });
});
