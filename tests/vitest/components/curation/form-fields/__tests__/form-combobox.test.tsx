import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { useForm } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';

import { FormCombobox } from '@/components/curation/form-fields/form-combobox';
import { Form } from '@/components/ui/form';

function FormWrapper({
    children,
    defaultValues = { testField: '' },
}: {
    children: (control: ReturnType<typeof useForm>['control']) => React.ReactNode;
    defaultValues?: Record<string, string>;
}) {
    const form = useForm({ defaultValues });
    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(vi.fn())}>{children(form.control)}</form>
        </Form>
    );
}

const options = [
    { value: 'en', label: 'English' },
    { value: 'de', label: 'German' },
    { value: 'fr', label: 'French' },
];

describe('FormCombobox', () => {
    it('renders label and combobox trigger', () => {
        render(
            <FormWrapper>
                {(control) => <FormCombobox control={control} name="testField" label="Language" options={options} />}
            </FormWrapper>,
        );
        expect(screen.getByText('Language')).toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('renders with custom placeholder', () => {
        render(
            <FormWrapper>
                {(control) => (
                    <FormCombobox control={control} name="testField" label="Language" options={options} placeholder="Choose language" />
                )}
            </FormWrapper>,
        );
        expect(screen.getByText('Choose language')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(
            <FormWrapper>
                {(control) => (
                    <FormCombobox
                        control={control}
                        name="testField"
                        label="Language"
                        options={options}
                        description="Select your preferred language"
                    />
                )}
            </FormWrapper>,
        );
        expect(screen.getByText('Select your preferred language')).toBeInTheDocument();
    });

    it('renders as disabled when disabled prop is true', () => {
        render(
            <FormWrapper>
                {(control) => <FormCombobox control={control} name="testField" label="Language" options={options} disabled />}
            </FormWrapper>,
        );
        expect(screen.getByRole('combobox')).toBeDisabled();
    });

    it('displays selected value', () => {
        render(
            <FormWrapper defaultValues={{ testField: 'de' }}>
                {(control) => <FormCombobox control={control} name="testField" label="Language" options={options} />}
            </FormWrapper>,
        );
        expect(screen.getByText('German')).toBeInTheDocument();
    });
});
