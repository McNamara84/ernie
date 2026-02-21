import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';

import { FormInput } from '@/components/curation/form-fields/form-input';
import { FormSelect } from '@/components/curation/form-fields/form-select';
import { FormTextarea } from '@/components/curation/form-fields/form-textarea';
import { Form } from '@/components/ui/form';

/**
 * Wrapper that provides react-hook-form context for form field testing
 */
function FormWrapper({
    children,
    defaultValues = { testField: '' },
}: {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    children: (control: any) => React.ReactNode;
    defaultValues?: Record<string, string>;
}) {
    const form = useForm({ defaultValues });
    return (
        <Form {...form}>
            {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
            <form onSubmit={form.handleSubmit(vi.fn())}>{children(form.control as any)}</form>
        </Form>
    );
}

describe('FormInput', () => {
    it('renders label and input', () => {
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Email" placeholder="Enter email" />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Email')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Enter email')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Name" description="Your full name" />}
            </FormWrapper>,
        );
        expect(screen.getByText('Your full name')).toBeInTheDocument();
    });

    it('renders as disabled when disabled prop is true', () => {
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Name" disabled />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Name')).toBeDisabled();
    });

    it('renders with correct input type', () => {
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Password" type="password" />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
    });

    it('accepts user input', async () => {
        const user = userEvent.setup();
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Name" />}
            </FormWrapper>,
        );
        const input = screen.getByLabelText('Name');
        await user.type(input, 'test value');
        expect(input).toHaveValue('test value');
    });

    it('renders with autoComplete attribute', () => {
        render(
            <FormWrapper>
                {(control) => <FormInput control={control} name="testField" label="Email" autoComplete="email" />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Email')).toHaveAttribute('autocomplete', 'email');
    });
});

describe('FormTextarea', () => {
    it('renders label and textarea', () => {
        render(
            <FormWrapper>
                {(control) => <FormTextarea control={control} name="testField" label="Description" placeholder="Enter description" />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Description')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Enter description')).toBeInTheDocument();
    });

    it('renders with custom rows', () => {
        render(
            <FormWrapper>
                {(control) => <FormTextarea control={control} name="testField" label="Notes" rows={8} />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Notes')).toHaveAttribute('rows', '8');
    });

    it('renders description when provided', () => {
        render(
            <FormWrapper>
                {(control) => <FormTextarea control={control} name="testField" label="Bio" description="Max 500 characters" />}
            </FormWrapper>,
        );
        expect(screen.getByText('Max 500 characters')).toBeInTheDocument();
    });

    it('renders as disabled when disabled prop is true', () => {
        render(
            <FormWrapper>
                {(control) => <FormTextarea control={control} name="testField" label="Notes" disabled />}
            </FormWrapper>,
        );
        expect(screen.getByLabelText('Notes')).toBeDisabled();
    });

    it('accepts user input', async () => {
        const user = userEvent.setup();
        render(
            <FormWrapper>
                {(control) => <FormTextarea control={control} name="testField" label="Notes" />}
            </FormWrapper>,
        );
        const textarea = screen.getByLabelText('Notes');
        await user.type(textarea, 'Some notes');
        expect(textarea).toHaveValue('Some notes');
    });
});

describe('FormSelect', () => {
    const options = [
        { value: 'a', label: 'Option A' },
        { value: 'b', label: 'Option B' },
        { value: 'c', label: 'Option C', disabled: true },
    ];

    it('renders label and select trigger', () => {
        render(
            <FormWrapper>
                {(control) => <FormSelect control={control} name="testField" label="Category" options={options} />}
            </FormWrapper>,
        );
        expect(screen.getByText('Category')).toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('renders with placeholder', () => {
        render(
            <FormWrapper>
                {(control) => (
                    <FormSelect control={control} name="testField" label="Category" options={options} placeholder="Pick one" />
                )}
            </FormWrapper>,
        );
        expect(screen.getByText('Pick one')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(
            <FormWrapper>
                {(control) => (
                    <FormSelect
                        control={control}
                        name="testField"
                        label="Type"
                        options={options}
                        description="Choose a type"
                    />
                )}
            </FormWrapper>,
        );
        expect(screen.getByText('Choose a type')).toBeInTheDocument();
    });

    it('renders as disabled when disabled prop is true', () => {
        render(
            <FormWrapper>
                {(control) => <FormSelect control={control} name="testField" label="Type" options={options} disabled />}
            </FormWrapper>,
        );
        expect(screen.getByRole('combobox')).toBeDisabled();
    });

    it('shows options when clicked', async () => {
        const user = userEvent.setup();
        render(
            <FormWrapper>
                {(control) => <FormSelect control={control} name="testField" label="Type" options={options} />}
            </FormWrapper>,
        );
        await user.click(screen.getByRole('combobox'));
        expect(screen.getByRole('option', { name: 'Option A' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Option B' })).toBeInTheDocument();
    });

    it('renders with default value', () => {
        render(
            <FormWrapper defaultValues={{ testField: 'b' }}>
                {(control) => <FormSelect control={control} name="testField" label="Type" options={options} />}
            </FormWrapper>,
        );
        // The trigger should show the selected option label
        const trigger = screen.getByRole('combobox');
        expect(trigger).toHaveTextContent('Option B');
    });
});
