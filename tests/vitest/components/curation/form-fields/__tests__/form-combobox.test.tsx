import { render, screen } from '@testing-library/react';
import { useForm, FormProvider } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { FormCombobox } from '@/components/curation/form-fields/form-combobox';

const options = [
    { value: 'en', label: 'English' },
    { value: 'de', label: 'German' },
    { value: 'fr', label: 'French' },
];

function TestWrapper({ disabled = false }: { disabled?: boolean }) {
    const form = useForm({ defaultValues: { language: '' } });

    return (
        <FormProvider {...form}>
            <form>
                <FormCombobox
                    control={form.control}
                    name="language"
                    label="Language"
                    description="Select the language"
                    options={options}
                    disabled={disabled}
                    placeholder="Pick a language"
                    emptyMessage="No languages found."
                />
            </form>
        </FormProvider>
    );
}

describe('FormCombobox', () => {
    it('renders the label', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Language')).toBeInTheDocument();
    });

    it('renders the description', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Select the language')).toBeInTheDocument();
    });

    it('renders without description when not provided', () => {
        function Minimal() {
            const form = useForm({ defaultValues: { language: '' } });
            return (
                <FormProvider {...form}>
                    <form>
                        <FormCombobox control={form.control} name="language" label="Lang" options={options} />
                    </form>
                </FormProvider>
            );
        }
        render(<Minimal />);
        expect(screen.getByText('Lang')).toBeInTheDocument();
    });
});
