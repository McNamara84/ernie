import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EditorSettings from '../settings/index';

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
                maxTitles={1}
                maxLicenses={1}
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
                maxTitles={1}
                maxLicenses={1}
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
                maxTitles={1}
                maxLicenses={1}
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
                maxTitles={1}
                maxLicenses={1}
            />,
        );
        const grid = screen.getByLabelText('Max Titles').closest('div')!.parentElement;
        expect(grid).not.toHaveClass('mt-8');
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
                maxTitles={1}
                maxLicenses={1}
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
                maxTitles={1}
                maxLicenses={1}
            />,
        );
        fireEvent.click(screen.getByLabelText('ERNIE active'));
        expect(setData).toHaveBeenCalledWith('languages', [
            { id: 1, code: 'en', name: 'English', active: true, elmo_active: false },
        ]);
    });
});

