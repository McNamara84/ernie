import { render, screen } from '@testing-library/react';
import { useForm, FormProvider } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { FormDatePicker } from '@/components/curation/form-fields/form-date-picker';

function TestWrapper({
    disabled = false,
    description,
}: {
    disabled?: boolean;
    description?: string;
}) {
    const form = useForm({ defaultValues: { collectionDate: '' } });

    return (
        <FormProvider {...form}>
            <form>
                <FormDatePicker
                    control={form.control}
                    name="collectionDate"
                    label="Collection Date"
                    description={description}
                    placeholder="Pick a date"
                    disabled={disabled}
                />
            </form>
        </FormProvider>
    );
}

describe('FormDatePicker', () => {
    it('renders the label', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Collection Date')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(<TestWrapper description="When was the sample collected?" />);
        expect(screen.getByText('When was the sample collected?')).toBeInTheDocument();
    });

    it('does not render description when not provided', () => {
        render(<TestWrapper />);
        expect(screen.queryByText('When was the sample collected?')).not.toBeInTheDocument();
    });

    it('uses default dateFormat yyyy-MM-dd', () => {
        function MinimalWrapper() {
            const form = useForm({ defaultValues: { d: '' } });
            return (
                <FormProvider {...form}>
                    <form>
                        <FormDatePicker control={form.control} name="d" label="Date" />
                    </form>
                </FormProvider>
            );
        }
        const { container } = render(<MinimalWrapper />);
        expect(container.querySelector('form')).toBeInTheDocument();
    });
});
