import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { EditorFieldProps } from '@/components/curation/fields/editor-field';
import { InputField } from '@/components/curation/fields/input-field';
import { SelectField } from '@/components/curation/fields/select-field';
import { TooltipProvider } from '@/components/ui/tooltip';

const selectProps = { value: 'sample', options: [{ value: 'sample', label: 'Sample' }], onValueChange: vi.fn() };
const fields = [
    { name: 'textbox', role: 'textbox', control: (props: EditorFieldProps) => <InputField {...props} /> },
    { name: 'select', role: 'combobox', control: (props: EditorFieldProps) => <SelectField {...props} {...selectProps} /> },
    { name: 'searchable select', role: 'combobox', control: (props: EditorFieldProps) => <SelectField {...props} {...selectProps} searchable /> },
];

describe.each(fields)('$name descriptions', ({ role, control }) => {
    const base = { id: 'sample', label: 'Sample type' };

    it('links the control to the help, caller descriptions and currently displayed feedback', () => {
        const view = (touched: boolean) => (
            <>
                <p id="policy">Catalogue policy.</p>
                {control({
                    ...base,
                    touched,
                    helpText: 'Choose a known type.',
                    'aria-describedby': 'policy  policy sample-help',
                    validationMessages: [{ severity: 'error', message: 'Type is missing.' }],
                })}
            </>
        );
        const { rerender } = render(view(false));
        const field = screen.getByRole(role, { name: base.label });
        const group = screen.getByRole('group');
        expect(field).toHaveAttribute('aria-describedby', 'policy sample-help');
        expect(field).toHaveAccessibleDescription('Catalogue policy. Choose a known type.');
        expect(field).toHaveAttribute('aria-invalid', 'false');
        expect(group).toHaveAttribute('data-invalid', 'false');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        rerender(view(true));
        expect(field).toHaveAttribute('aria-describedby', 'policy sample-help sample-feedback');
        expect(field).toHaveAccessibleDescription(/Catalogue policy\. Choose a known type\..*Type is missing\./);
        expect(screen.getByRole('alert')).toHaveAttribute('id', 'sample-feedback');
        expect(field).toBeInvalid();
        expect(group).toHaveAttribute('data-invalid', 'true');

        rerender(view(false));
        expect(field).toHaveAccessibleDescription('Catalogue policy. Choose a known type.');
        expect(field).toHaveAttribute('aria-describedby', 'policy sample-help');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('drops the description when all feedback is hidden and still describes warnings', () => {
        const success = { severity: 'success', message: 'Looks good.' } as const;
        const warning = { severity: 'warning', message: 'Check this type.' } as const;
        const props = { ...base, touched: true, hideLabel: true, showSuccessFeedback: false };
        const { rerender } = render(control({ ...props, validationMessages: [success] }));
        const field = screen.getByRole(role, { name: base.label });
        expect(field).not.toHaveAttribute('aria-describedby');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        rerender(control({ ...props, validationMessages: [success, warning] }));
        expect(field).toHaveAttribute('aria-describedby', 'sample-feedback');
        expect(field).toHaveAccessibleDescription(/Check this type\./);
        expect(screen.queryByText(success.message)).not.toBeInTheDocument();
        expect(field).toHaveAttribute('aria-invalid', 'false');
        expect(screen.getByRole('group')).toHaveAttribute('data-invalid', 'false');
    });
});

it('preserves explicit input validity and associates a tooltip label with the input', () => {
    const view = (invalid: 'spelling' | 'false') => (
        <TooltipProvider>
            <InputField id="title" label="Title" labelTooltip="Use the original title" aria-invalid={invalid} />
        </TooltipProvider>
    );
    const { rerender } = render(view('spelling'));
    const input = screen.getByRole('textbox', { name: 'Title' });
    expect(screen.getByText('Title')).toHaveAttribute('for', input.id);
    expect(input).toHaveAttribute('aria-invalid', 'spelling');
    expect(screen.getByRole('group')).toHaveAttribute('data-invalid', 'true');

    rerender(view('false'));
    expect(input).toHaveAttribute('aria-invalid', 'false');
    expect(screen.getByRole('group')).toHaveAttribute('data-invalid', 'false');
});
