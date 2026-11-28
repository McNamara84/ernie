import { useForm } from 'react-hook-form';

import { FormCombobox } from '@/components/curation/form-fields/form-combobox';
import { Form } from '@/components/ui/form';

import { expect, page, render, test, vi } from '../helpers/test';

function LanguageForm({ onSubmit, disabled = false }: { onSubmit: (values: { language: string }) => void; disabled?: boolean }) {
    const form = useForm({ defaultValues: { language: '' } });
    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit((values) => onSubmit(values))}>
                <FormCombobox
                    control={form.control}
                    name="language"
                    label="Language"
                    disabled={disabled}
                    options={[
                        { value: 'en', label: 'English' },
                        { value: 'de', label: 'German' },
                    ]}
                />
                <button type="submit">Save</button>
            </form>
        </Form>
    );
}

test('submits the selected value through React Hook Form', async () => {
    const onSubmit = vi.fn();
    await render(<LanguageForm onSubmit={onSubmit} />);
    await page.getByRole('combobox').click();
    await page.getByRole('option', { name: 'German' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect.poll(() => onSubmit.mock.calls).toEqual([[{ language: 'de' }]]);
});

test('does not open a disabled form combobox', async () => {
    await render(<LanguageForm onSubmit={vi.fn()} disabled />);
    await expect.element(page.getByRole('combobox')).toBeDisabled();
    await expect.element(page.getByRole('listbox')).not.toBeInTheDocument();
});
