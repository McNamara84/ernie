import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import DataCiteForm from '../datacite-form';
import { LANGUAGE_OPTIONS } from '@/constants/languages';
import type { ResourceType, TitleType } from '@/types';

describe('DataCiteForm', () => {
    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    const resourceTypes: ResourceType[] = [
        { id: 1, name: 'Dataset', slug: 'dataset' },
    ];

    const titleTypes: TitleType[] = [
        { id: 1, name: 'Main Title', slug: 'main-title' },
        { id: 2, name: 'Alternative Title', slug: 'alternative-title' },
    ];

    it('renders fields, title options and supports adding/removing titles', async () => {
        render(<DataCiteForm resourceTypes={resourceTypes} titleTypes={titleTypes} />);
        // basic fields
        expect(screen.getByLabelText('DOI')).toBeInTheDocument();
        expect(screen.getByLabelText('Year')).toBeInTheDocument();
        expect(screen.getByLabelText('Version')).toBeInTheDocument();

        // resource type option
        const resourceTypeTrigger = screen.getByLabelText('Resource Type');
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.click(resourceTypeTrigger);
        expect(
            await screen.findByRole('option', { name: 'Dataset' }),
        ).toBeInTheDocument();

        // language options
        const languageTrigger = screen.getByLabelText('Language of Data');
        await user.click(languageTrigger);
        for (const option of LANGUAGE_OPTIONS) {
            expect(
                await screen.findByRole('option', { name: option.label }),
            ).toBeInTheDocument();
        }
        await user.keyboard('{Escape}');

        // title fields
        expect(screen.getByRole('textbox', { name: 'Title' })).toBeInTheDocument();
        const titleTypeTrigger = screen.getByRole('combobox', { name: 'Title Type' });
        expect(titleTypeTrigger).toHaveTextContent('Main Title');

        // add and remove title rows
        const addButton = screen.getByRole('button', { name: 'Add title' });
        await user.click(addButton);
        const titleInputs = screen.getAllByRole('textbox', { name: 'Title' });
        expect(titleInputs).toHaveLength(2);
        const secondTitleTypeTrigger = screen.getAllByRole('combobox', { name: 'Title Type' })[1];
        expect(secondTitleTypeTrigger).toHaveTextContent('Alternative Title');
        await user.click(secondTitleTypeTrigger);
        expect(screen.queryByRole('option', { name: 'Main Title' })).not.toBeInTheDocument();
        await user.click(secondTitleTypeTrigger);
        const removeButton = screen.getByRole('button', { name: 'Remove title' });
        await user.click(removeButton);
        expect(screen.getAllByRole('textbox', { name: 'Title' })).toHaveLength(1);
    });
});
