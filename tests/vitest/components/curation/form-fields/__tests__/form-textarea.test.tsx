import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm, FormProvider } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { FormTextarea } from '@/components/curation/form-fields/form-textarea';

function TestWrapper({
    disabled = false,
    description,
    rows,
    placeholder,
}: {
    disabled?: boolean;
    description?: string;
    rows?: number;
    placeholder?: string;
}) {
    const form = useForm({ defaultValues: { abstract: '' } });

    return (
        <FormProvider {...form}>
            <form>
                <FormTextarea
                    control={form.control}
                    name="abstract"
                    label="Abstract"
                    description={description}
                    placeholder={placeholder}
                    rows={rows}
                    disabled={disabled}
                />
            </form>
        </FormProvider>
    );
}

describe('FormTextarea', () => {
    it('renders with label', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Abstract')).toBeInTheDocument();
    });

    it('renders a textarea element', () => {
        render(<TestWrapper placeholder="Enter abstract" />);
        const textarea = screen.getByPlaceholderText('Enter abstract');
        expect(textarea.tagName).toBe('TEXTAREA');
    });

    it('renders description when provided', () => {
        render(<TestWrapper description="A brief summary of the dataset" />);
        expect(screen.getByText('A brief summary of the dataset')).toBeInTheDocument();
    });

    it('uses default 4 rows', () => {
        render(<TestWrapper placeholder="text" />);
        expect(screen.getByPlaceholderText('text')).toHaveAttribute('rows', '4');
    });

    it('accepts custom rows', () => {
        render(<TestWrapper rows={8} placeholder="text" />);
        expect(screen.getByPlaceholderText('text')).toHaveAttribute('rows', '8');
    });

    it('disables textarea when disabled prop is true', () => {
        render(<TestWrapper disabled placeholder="text" />);
        expect(screen.getByPlaceholderText('text')).toBeDisabled();
    });

    it('accepts user input', async () => {
        const user = userEvent.setup();
        render(<TestWrapper placeholder="type here" />);

        const textarea = screen.getByPlaceholderText('type here');
        await user.type(textarea, 'My abstract text');

        expect(textarea).toHaveValue('My abstract text');
    });
});
