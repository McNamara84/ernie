import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
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
    it('renders resource types and settings fields', () => {
        render(
            <EditorSettings
                resourceTypes={[{ id: 1, name: 'Dataset' }]}
                maxTitles={10}
                maxLicenses={5}
            />,
        );
        expect(screen.getByLabelText('Name')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Titles')).toBeInTheDocument();
        expect(screen.getByLabelText('Max Licenses')).toBeInTheDocument();
        expect(useFormMock).toHaveBeenCalled();
    });
});
