import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm, FormProvider } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';

import { FormInput } from '@/components/curation/form-fields/form-input';

function TestWrapper({
    disabled = false,
    description,
    type = 'text',
    placeholder,
}: {
    disabled?: boolean;
    description?: string;
    type?: 'text' | 'email' | 'password' | 'number' | 'url' | 'tel';
    placeholder?: string;
}) {
    const form = useForm({ defaultValues: { title: '' } });

    return (
        <FormProvider {...form}>
            <form>
                <FormInput
                    control={form.control}
                    name="title"
                    label="Title"
                    description={description}
                    placeholder={placeholder}
                    type={type}
                    disabled={disabled}
                />
            </form>
        </FormProvider>
    );
}

describe('FormInput', () => {
    it('renders with label', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Title')).toBeInTheDocument();
    });

    it('renders an input element', () => {
        render(<TestWrapper placeholder="Enter title" />);
        expect(screen.getByPlaceholderText('Enter title')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(<TestWrapper description="The main title of the resource" />);
        expect(screen.getByText('The main title of the resource')).toBeInTheDocument();
    });

    it('does not render description when not provided', () => {
        const { container } = render(<TestWrapper />);
        expect(container.querySelector('[id$="-form-item-description"]')).not.toBeInTheDocument();
    });

    it('renders with correct input type', () => {
        render(<TestWrapper type="email" placeholder="email" />);
        const input = screen.getByPlaceholderText('email');
        expect(input).toHaveAttribute('type', 'email');
    });

    it('disables input when disabled prop is true', () => {
        render(<TestWrapper disabled placeholder="input" />);
        expect(screen.getByPlaceholderText('input')).toBeDisabled();
    });

    it('accepts user input', async () => {
        const user = userEvent.setup();
        render(<TestWrapper placeholder="type here" />);

        const input = screen.getByPlaceholderText('type here');
        await user.type(input, 'My Dataset');

        expect(input).toHaveValue('My Dataset');
    });

    it('renders with different types', () => {
        const types: Array<'text' | 'email' | 'password' | 'number' | 'url' | 'tel'> = [
            'text',
            'email',
            'password',
            'number',
            'url',
            'tel',
        ];

        types.forEach((type) => {
            const { unmount } = render(<TestWrapper type={type} placeholder={`${type}-input`} />);
            const input = screen.getByPlaceholderText(`${type}-input`);
            expect(input).toHaveAttribute('type', type);
            unmount();
        });
    });
});
