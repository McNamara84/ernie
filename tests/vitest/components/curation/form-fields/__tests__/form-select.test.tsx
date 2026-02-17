import { render, screen } from '@testing-library/react';
import { useForm, FormProvider } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { FormSelect } from '@/components/curation/form-fields/form-select';

const options = [
    { value: 'dataset', label: 'Dataset' },
    { value: 'software', label: 'Software' },
    { value: 'text', label: 'Text', disabled: true },
];

function TestWrapper({
    disabled = false,
    description,
}: {
    disabled?: boolean;
    description?: string;
}) {
    const form = useForm({ defaultValues: { resourceType: '' } });

    return (
        <FormProvider {...form}>
            <form>
                <FormSelect
                    control={form.control}
                    name="resourceType"
                    label="Resource Type"
                    description={description}
                    placeholder="Choose type"
                    options={options}
                    disabled={disabled}
                />
            </form>
        </FormProvider>
    );
}

describe('FormSelect', () => {
    it('renders the label', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Resource Type')).toBeInTheDocument();
    });

    it('renders the placeholder', () => {
        render(<TestWrapper />);
        expect(screen.getByText('Choose type')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(<TestWrapper description="Select the type of resource" />);
        expect(screen.getByText('Select the type of resource')).toBeInTheDocument();
    });

    it('does not render description when not provided', () => {
        const { container } = render(<TestWrapper />);
        expect(container.querySelector('[id$="-form-item-description"]')).not.toBeInTheDocument();
    });

    it('renders with default placeholder when not specified', () => {
        function MinimalWrapper() {
            const form = useForm({ defaultValues: { rt: '' } });
            return (
                <FormProvider {...form}>
                    <form>
                        <FormSelect control={form.control} name="rt" label="Type" options={options} />
                    </form>
                </FormProvider>
            );
        }
        render(<MinimalWrapper />);
        expect(screen.getByText('Select an option')).toBeInTheDocument();
    });
});
