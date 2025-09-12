import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EditorSettings from '../settings/index';

const setData = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    useForm: (initial: unknown) => ({
        data: initial as any,
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
    it('renders ELMO active column and updates value when toggled', () => {
        const resourceTypes = [
            { id: 1, name: 'Dataset', active: true, elmo_active: false },
        ];
        render(
            <EditorSettings resourceTypes={resourceTypes} maxTitles={1} maxLicenses={1} />,
        );
        expect(
            screen.getByRole('columnheader', { name: 'ELMO active' }),
        ).toBeInTheDocument();
        const checkbox = screen.getByLabelText('ELMO active');
        fireEvent.click(checkbox);
        expect(setData).toHaveBeenCalledWith('resourceTypes', [
            { id: 1, name: 'Dataset', active: true, elmo_active: true },
        ]);
    });
});

