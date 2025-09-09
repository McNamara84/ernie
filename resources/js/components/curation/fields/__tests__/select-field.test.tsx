import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import { SelectField } from '../select-field';

describe('SelectField', () => {
    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    it('renders options when opened', async () => {
        render(
            <SelectField
                id="language"
                label="Language"
                value=""
                onValueChange={() => {}}
                options={[{ value: 'en', label: 'English' }]}
            />
        );
        const trigger = screen.getByLabelText('Language');
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.click(trigger);
        expect(await screen.findByText('English')).toBeInTheDocument();
    });

    it('applies custom className', () => {
        render(
            <SelectField
                id="lang"
                label="Lang"
                value=""
                onValueChange={() => {}}
                options={[]}
                className="col-span-2"
            />
        );
        const trigger = screen.getByLabelText('Lang');
        const wrapper = trigger.closest('.col-span-2');
        expect(wrapper).toHaveClass('col-span-2');
    });
});
